using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace VkCrmUpdateDemo;

sealed record DemoState(string Product, string Version);
sealed record UpdateEntry(string Path, string Sha256);
sealed record UpdateManifest(int Format, string Product, string From, string To, UpdateEntry[] Files);

static class DemoUpdate
{
    public const string Product = "VK-CRM-UPDATE-DEMO-ONLY";
    public const string Marker = ".demo-install.json";
    public static readonly string[] Preserved = [".env", "database/customers.json", "storage/app/public/photo.txt"];

    private static string DemoParent => Path.Combine(Path.GetTempPath(), "VkCrmUpdateDemo");
    public static string CreateFixture()
    {
        var root = Path.Combine(Path.GetFullPath(DemoParent), Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        Write(root, Marker, JsonSerializer.Serialize(new DemoState(Product, "1.0")));
        Write(root, ".env", "DB_CONNECTION=sqlsrv\nDB_HOST=DEMO-SERVER\nDB_DATABASE=DEMO-CRM\n");
        Write(root, "database/customers.json", "{\"customers\":[\"Khách mẫu A\",\"Khách mẫu B\"],\"accounts\":[\"Quản trị mẫu\"]}");
        Write(root, "storage/app/public/photo.txt", "Ảnh minh họa — không phải ảnh khách thật");
        Write(root, "app/demo-version.txt", "Chương trình phiên bản 1.0");
        Write(root, "public/assets/js/demo.js", "// phiên bản 1.0");
        return root;
    }

    public static byte[] Package(Dictionary<string, string>? content = null)
    {
        content ??= new() { ["app/demo-version.txt"] = "Chương trình phiên bản 1.1", ["public/assets/js/demo.js"] = "// phiên bản 1.1 — đã sửa lỗi" };
        using var output = new MemoryStream();
        using (var zip = new ZipArchive(output, ZipArchiveMode.Create, true))
        {
            var files = new List<UpdateEntry>();
            foreach (var pair in content)
            {
                var data = Encoding.UTF8.GetBytes(pair.Value);
                using (var stream = zip.CreateEntry(pair.Key).Open()) stream.Write(data);
                files.Add(new(pair.Key, Hash(data)));
            }
            using var manifest = zip.CreateEntry("manifest.json").Open();
            JsonSerializer.Serialize(manifest, new UpdateManifest(1, Product, "1.0", "1.1", files.ToArray()));
        }
        return output.ToArray();
    }

    public static Dictionary<string, string> Fingerprints(string root) => Preserved.ToDictionary(path => path, path => Hash(File.ReadAllBytes(Path.Combine(root, path))));
    public static string Version(string root) => ReadState(root).Version;

    public static void Apply(string root, byte[] package, IProgress<(int Percent, string Text)>? progress = null, Action<int>? afterWrite = null)
    {
        root = Path.GetFullPath(root);
        if (!root.StartsWith(Path.GetFullPath(DemoParent).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase)) throw new IOException("Chỉ cập nhật dữ liệu minh họa trong thư mục tạm.");
        RejectLinks(root);
        var state = ReadState(root);
        if (state.Product != Product) throw new IOException("Chỉ nhận bản minh họa. Không cập nhật bản CRM đang dùng.");
        using var updateLock = new FileStream(Path.Combine(root, ".demo-update.lock"), FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None);
        state = ReadState(root);
        progress?.Report((10, "Kiểm tra gói cập nhật…"));
        var (manifest, files) = Validate(package);
        if (state.Version != manifest.From) throw new IOException("Phiên bản không phù hợp hoặc đã được cập nhật.");
        var preserved = Fingerprints(root);
        foreach (var item in manifest.Files) _ = Resolve(root, item.Path);
        var job = Guid.NewGuid().ToString("N");
        var stage = Path.Combine(root, ".demo-stage", job);
        var backup = Path.Combine(root, ".demo-backups", job);
        RejectLinks(stage); RejectLinks(backup);
        Directory.CreateDirectory(stage); Directory.CreateDirectory(backup);
        var originals = new Dictionary<string, byte[]?>();
        var oldMarker = File.ReadAllBytes(Path.Combine(root, Marker));
        progress?.Report((30, "Chuẩn bị bản khôi phục…"));
        foreach (var file in files)
        {
            var target = Resolve(root, file.Key);
            originals[file.Key] = File.Exists(target) ? File.ReadAllBytes(target) : null;
            if (originals[file.Key] is { } bytes) WriteBytes(backup, file.Key, bytes);
            WriteBytes(stage, file.Key, file.Value);
        }
        File.WriteAllBytes(Path.Combine(backup, Marker), oldMarker);
        var written = new List<string>();
        try
        {
            progress?.Report((55, "Đang cập nhật chương trình…"));
            foreach (var item in files)
            {
                var target = Resolve(root, item.Key);
                Directory.CreateDirectory(Path.GetDirectoryName(target)!);
                // Add to rollback list before the replace, including failures mid-write.
                written.Add(item.Key);
                File.Move(Path.Combine(stage, item.Key), target, true);
                afterWrite?.Invoke(written.Count);
            }
            progress?.Report((85, "Kiểm tra dữ liệu và cấu hình…"));
            foreach (var item in manifest.Files)
                if (Hash(File.ReadAllBytes(Resolve(root, item.Path))) != item.Sha256) throw new IOException("Tệp cập nhật không vượt qua kiểm tra.");
            RequirePreserved(root, preserved);
            Write(root, Marker, JsonSerializer.Serialize(new DemoState(Product, manifest.To)));
            progress?.Report((100, "Cập nhật hoàn tất. Cấu hình và dữ liệu mẫu giữ nguyên."));
        }
        catch
        {
            foreach (var path in written.AsEnumerable().Reverse())
            {
                var target = Resolve(root, path);
                if (originals[path] is { } bytes) File.WriteAllBytes(target, bytes);
                else if (File.Exists(target)) File.Delete(target); // Only newly created, allowlisted demo code files.
            }
            File.WriteAllBytes(Path.Combine(root, Marker), oldMarker);
            RequirePreserved(root, preserved);
            throw;
        }
    }

    private static (UpdateManifest Manifest, Dictionary<string, byte[]> Files) Validate(byte[] package)
    {
        if (package.Length > 2_000_000) throw new IOException("Gói minh họa vượt giới hạn.");
        using var input = new MemoryStream(package);
        using var zip = new ZipArchive(input, ZipArchiveMode.Read);
        if (zip.Entries.Count > 50 || zip.Entries.Sum(entry => entry.Length) > 2_000_000 || zip.Entries.Any(entry => entry.Length > 512_000)) throw new IOException("Nội dung gói vượt giới hạn.");
        var entries = new Dictionary<string, ZipArchiveEntry>(StringComparer.OrdinalIgnoreCase);
        foreach (var entry in zip.Entries)
            if (!entries.TryAdd(entry.FullName, entry)) throw new IOException("Tệp trùng trong gói cập nhật.");
        if (!entries.TryGetValue("manifest.json", out var metadata)) throw new IOException("Thiếu thông tin gói.");
        using var metaStream = metadata.Open();
        var manifest = JsonSerializer.Deserialize<UpdateManifest>(metaStream) ?? throw new IOException("Thông tin gói không hợp lệ.");
        if (manifest.Format != 1 || manifest.Product != Product || manifest.From != "1.0" || manifest.To != "1.1" || manifest.Files is not { Length: > 0 and <= 49 }) throw new IOException("Không đúng gói minh họa.");
        if (zip.Entries.Count != manifest.Files.Length + 1) throw new IOException("Gói có tệp ngoài danh sách.");
        var files = new Dictionary<string, byte[]>(StringComparer.OrdinalIgnoreCase);
        foreach (var item in manifest.Files)
        {
            ValidatePath(item.Path);
            if (!entries.TryGetValue(item.Path, out var entry)) throw new IOException("Thiếu tệp trong gói.");
            using var data = new MemoryStream(); using (var source = entry.Open()) source.CopyTo(data);
            var bytes = data.ToArray();
            if (!string.Equals(Hash(bytes), item.Sha256, StringComparison.Ordinal)) throw new IOException("Checksum không khớp. Không cập nhật.");
            if (!files.TryAdd(item.Path, bytes)) throw new IOException("Tệp trùng trong thông tin gói.");
        }
        return (manifest, files);
    }

    private static void ValidatePath(string path)
    {
        if (string.IsNullOrWhiteSpace(path) || path.Contains('\\') || path.Contains(':') || Path.IsPathRooted(path)
            || path.Split('/').Any(part => string.IsNullOrEmpty(part) || part is "." or ".." || part.StartsWith('.') || part.EndsWith('.') || part.EndsWith(' '))) throw new IOException("Đường dẫn gói không an toàn.");
        var allowed = path.StartsWith("app/", StringComparison.Ordinal) || path.StartsWith("public/assets/", StringComparison.Ordinal)
            || path.StartsWith("resources/", StringComparison.Ordinal) || path.StartsWith("routes/", StringComparison.Ordinal)
            || path.StartsWith("config/", StringComparison.Ordinal) || path == "bootstrap/app.php";
        var extension = Path.GetExtension(path).ToLowerInvariant();
        if (!allowed || !new[] { ".php", ".js", ".css", ".json", ".txt", ".svg", ".png", ".ico", ".xlsx", ".woff", ".woff2" }.Contains(extension)) throw new IOException("Gói không được thay cấu hình kết nối, database hoặc thư mục dữ liệu.");
    }

    private static string Resolve(string root, string path)
    {
        ValidatePath(path);
        var target = Path.GetFullPath(Path.Combine(root, path));
        if (!target.StartsWith(root.TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase)) throw new IOException("Đường dẫn vượt thư mục minh họa.");
        RejectLinks(target);
        return target;
    }

    private static void RejectLinks(string path)
    {
        for (var current = Path.GetFullPath(path); !string.IsNullOrEmpty(current); current = Path.GetDirectoryName(current))
            if ((File.Exists(current) || Directory.Exists(current)) && File.GetAttributes(current).HasFlag(FileAttributes.ReparsePoint)) throw new IOException("Không cập nhật qua liên kết thư mục.");
    }

    private static DemoState ReadState(string root) => JsonSerializer.Deserialize<DemoState>(File.ReadAllText(Path.Combine(root, Marker))) ?? throw new IOException("Không phải bản minh họa.");
    private static void RequirePreserved(string root, Dictionary<string, string> expected)
    {
        var actual = Fingerprints(root);
        if (expected.Any(pair => !actual.TryGetValue(pair.Key, out var value) || value != pair.Value)) throw new IOException("Dữ liệu mẫu hoặc cấu hình đã thay đổi.");
    }
    private static string Hash(byte[] data) => Convert.ToHexString(SHA256.HashData(data));
    private static void Write(string root, string path, string content) => WriteBytes(root, path, Encoding.UTF8.GetBytes(content));
    private static void WriteBytes(string root, string path, byte[] bytes) { var file = Path.Combine(root, path); Directory.CreateDirectory(Path.GetDirectoryName(file)!); File.WriteAllBytes(file, bytes); }
}
