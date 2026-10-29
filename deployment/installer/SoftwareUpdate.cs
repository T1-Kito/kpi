using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;

// Only updates code against an already-compatible schema. Never initializes a database.
internal static class SoftwareUpdate
{
    internal const string Version = "0.8.18";
    internal sealed record Change(string Name, string Hash, bool Existed, string? Before);
    internal sealed record Journal(string State, List<Change> Files);
    internal static void ValidateJournal(Journal journal)
    {
        var names = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        if (journal.Files is null || journal.Files.Count > 10000) throw new IOException("Nhật ký cập nhật không hợp lệ.");
        foreach (var change in journal.Files)
            if ((!Allowed(change.Name) && change.Name != "PACKAGE-MANIFEST.json" && change.Name != "VK CRM.exe") || !names.Add(change.Name)
                || !IsHash(change.Hash) || (change.Existed ? !IsHash(change.Before) : change.Before is not null))
                throw new IOException("Nhật ký cập nhật không hợp lệ.");
    }
    static bool IsHash(string? value) => value is not null && value.Length == 64 && value.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f');
    internal static string Hash(string path) { using var stream=File.OpenRead(path);return Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant(); }
    internal static void EnsureReadyToStart(string root)
    {
        var path = Resolve(root, "storage/app/private/software-update/journal.json");
        if (File.Exists(path))
        {
            var journal = JsonSerializer.Deserialize<Journal>(File.ReadAllText(path));
            if (journal?.State == "publishing") throw new IOException("Cập nhật chưa hoàn tất. Mở bộ cài và chọn Cập nhật để khôi phục trước khi khởi động CRM.");
        }
        var down = Resolve(root, "storage/framework/down");
        if (File.Exists(down)) throw new IOException("CRM đang bảo trì. Chưa thể khởi động cho người dùng.");
    }
    internal static void NoLinks(string path)
    {
        for (var node = new DirectoryInfo(Path.GetFullPath(path)); node is not null; node = node.Parent)
            if (node.Exists && (node.Attributes & FileAttributes.ReparsePoint) != 0)
                throw new IOException("Không cập nhật qua thư mục liên kết.");
        if (File.Exists(path) && (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0)
            throw new IOException("Không cập nhật qua tệp liên kết.");
    }
    internal static string Resolve(string root, string name)
    {
        if (name.Contains('\\') || name.Contains(':') || name.StartsWith('/') || name.Split('/').Any(p => p is "" or "." or ".." || p.EndsWith('.') || p.EndsWith(' ')))
            throw new IOException("Đường dẫn gói cập nhật không hợp lệ.");
        var result = Path.GetFullPath(Path.Combine(root, name));
        var boundary=Path.TrimEndingDirectorySeparator(Path.GetFullPath(root));
        if(!Path.EndsInDirectorySeparator(boundary))boundary+=Path.DirectorySeparatorChar;
        if (!result.StartsWith(boundary, StringComparison.OrdinalIgnoreCase))
            throw new IOException("Tệp nằm ngoài thư mục ứng dụng.");
        NoLinks(result);
        return result;
    }
    internal static bool Allowed(string name) =>
        new[] { "app/", "config/", "routes/", "resources/", "public/assets/", "database/migrations/", "deployment/windows/" }.Any(name.StartsWith)
        || new[] { "artisan", "composer.json", "composer.lock", "bootstrap/app.php", "bootstrap/providers.php", "public/index.php", "public/.htaccess", "public/robots.txt", "public/favicon.ico" }.Contains(name);

    internal static void ValidateInstallation(string root)
    {
        NoLinks(root);
        using var marker = JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "vk-crm-installed.json")));
        if (marker.RootElement.GetProperty("format").GetInt32() != 1 || !marker.RootElement.GetProperty("initialized").GetBoolean()
            || !new[] { "sqlite", "sqlsrv" }.Contains(marker.RootElement.GetProperty("database").GetString()))
            throw new IOException("Thư mục không phải bản VK CRM đã thiết lập.");
        foreach (var name in new[] { ".env", "vendor/autoload.php", "runtime/php.exe", "runtime/php.ini", "composer.lock", "artisan", "PACKAGE-MANIFEST.json" })
            if (!File.Exists(Resolve(root, name))) throw new IOException("Bản cài thiếu thành phần. Giữ nguyên dữ liệu và liên hệ kỹ thuật.");
    }
    internal static void SaveJournal(string path, Journal value)
    {
        var temporary = path + ".tmp";
        using (var stream = new FileStream(temporary, FileMode.Create, FileAccess.Write, FileShare.None))
        { JsonSerializer.Serialize(stream, value); stream.Flush(true); }
        File.Move(temporary, path, true);
    }
    internal static List<Change> Stage(string root, string work, Stream source)
    {
        using var zip = new ZipArchive(source, ZipArchiveMode.Read, true);
        if (zip.Entries.Count > 10000 || zip.Entries.Sum(e => e.Length) > 300L * 1024 * 1024) throw new IOException("Gói vượt giới hạn an toàn.");
        var manifestEntry = zip.GetEntry("PACKAGE-MANIFEST.json") ?? throw new IOException("Thiếu danh mục kiểm tra gói.");
        if (manifestEntry.Length > 2L * 1024 * 1024) throw new IOException("Danh mục gói vượt giới hạn an toàn.");
        using var manifest = JsonDocument.Parse(manifestEntry.Open());
        var files = manifest.RootElement.GetProperty("files").EnumerateObject().ToDictionary(x => x.Name, x => x.Value.GetString()!, StringComparer.OrdinalIgnoreCase);
        using var previous = JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "PACKAGE-MANIFEST.json")));
        var oldFiles = previous.RootElement.GetProperty("files").EnumerateObject().ToDictionary(x => x.Name, x => x.Value.GetString()!, StringComparer.OrdinalIgnoreCase);
        var names = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        var changes = new List<Change>();
        foreach (var entry in zip.Entries)
        {
            if (!names.Add(entry.FullName)) throw new IOException("Gói chứa tên tệp trùng.");
            if (entry.FullName.EndsWith('/'))
            { Resolve(work, entry.FullName.TrimEnd('/')); if (entry.Length != 0) throw new IOException("Thư mục không hợp lệ."); continue; }
            if (entry.FullName == "PACKAGE-MANIFEST.json") continue;
            var name = entry.FullName;
            if (!Allowed(name) || !files.TryGetValue(name, out var expected) || entry.Length > 20L * 1024 * 1024) throw new IOException("Gói chứa tệp không được phép.");
            var target = Resolve(root, name); var staged = Resolve(Path.Combine(work, "stage"), name);
            Directory.CreateDirectory(Path.GetDirectoryName(staged)!);
            using (var input = entry.Open()) using (var output = new FileStream(staged, FileMode.CreateNew)) input.CopyTo(output);
            if (Hash(staged) != expected) throw new IOException("Kiểm tra toàn vẹn gói thất bại.");
            var before = File.Exists(target) ? Hash(target) : null;
            if (before == expected) continue;
            if (before is not null && (!oldFiles.TryGetValue(name, out var old) || old != before))
                throw new IOException("Có mã nguồn đã chỉnh riêng. Cần kỹ thuật đối chiếu trước khi cập nhật.");
            if (before is not null && name.StartsWith("database/migrations/"))
                throw new IOException("Gói thay đổi migration cũ. Cần kiểm tra tương thích database trước.");
            changes.Add(new Change(name, expected, before is not null, before));
        }
        if (files.Keys.Any(x => !names.Contains(x))) throw new IOException("Gói thiếu tệp trong danh mục.");
        if (Hash(Resolve(root, "composer.lock")) != files.GetValueOrDefault("composer.lock"))
            throw new IOException("Bản cập nhật cần nâng cấp thư viện. Liên hệ kỹ thuật; chưa thay đổi bản đang dùng.");
        var stagedManifest = Resolve(Path.Combine(work, "stage"), "PACKAGE-MANIFEST.json");
        using (var input = manifestEntry.Open()) using (var output = new FileStream(stagedManifest, FileMode.CreateNew)) input.CopyTo(output);
        var oldManifest = Resolve(root, "PACKAGE-MANIFEST.json");
        if (Hash(stagedManifest) != Hash(oldManifest)) changes.Add(new Change("PACKAGE-MANIFEST.json", Hash(stagedManifest), true, Hash(oldManifest)));
        // Empty ZIP directories are deliberately not extracted. Laravel still
        // needs a writable package-discovery cache before compatibility bootstrap.
        Directory.CreateDirectory(Resolve(Path.Combine(work, "stage"), "bootstrap/cache"));
        return changes;
    }
    internal static void Restore(string root, string work, Journal journal)
    {
        ValidateJournal(journal);
        // Validate every backup before changing a single target during recovery.
        foreach (var change in journal.Files)
            if (change.Existed && Hash(Resolve(Path.Combine(work, "backup"), change.Name)) != change.Before)
                throw new IOException("Bản sao lưu mã nguồn không toàn vẹn. Giữ nguyên thư mục để kiểm tra.");
        foreach (var change in journal.Files.AsEnumerable().Reverse())
        {
            var target = Resolve(root, change.Name);
            if (change.Existed) AtomicCopy(Resolve(Path.Combine(work, "backup"), change.Name), target);
            else if (File.Exists(target)) File.Delete(target);
        }
        SaveJournal(Path.Combine(work, "journal.json"), journal with { State = "rolled-back" });
    }
    static void AtomicCopy(string source, string target)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(target)!);
        var temporary = target + ".vk-update-" + Guid.NewGuid().ToString("N");
        try
        {
            using (var input = File.OpenRead(source)) using (var output = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write, FileShare.None))
            { input.CopyTo(output); output.Flush(true); }
            File.Move(temporary, target, true);
        }
        finally { if (File.Exists(temporary)) File.Delete(temporary); }
    }
    internal static async Task<string> Apply(string root, Stream package, Func<string, string, Task> check, Action<string> progress, Action<int>? fault = null, Func<string,string,Task>? upgrade = null, string? launcherSource=null)
    {
        root = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar);
        ValidateInstallation(root);
        var work = Resolve(root, "storage/app/private/software-update");
        Directory.CreateDirectory(work);
        using var exclusive = new FileStream(Path.Combine(work, "update.lock"), FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None);
        var journalPath = Path.Combine(work, "journal.json");
        var down = Resolve(root, "storage/framework/down");
        if (File.Exists(journalPath))
        {
            var prior = JsonSerializer.Deserialize<Journal>(File.ReadAllText(journalPath)) ?? throw new IOException("Nhật ký cập nhật không hợp lệ.");
            ValidateJournal(prior);
            if (prior.State == "publishing")
            {
                Restore(root, work, prior);
                if (File.Exists(down) && File.ReadAllText(down).Contains("VK_CRM_SOFTWARE_UPDATE")) File.Delete(down);
                throw new IOException("Đã khôi phục mã nguồn sau lần cập nhật gián đoạn. Kiểm tra ứng dụng rồi chạy lại bộ cài.");
            }
            if (prior.State == "completed" && File.Exists(down) && File.ReadAllText(down).Contains("VK_CRM_SOFTWARE_UPDATE"))
            {
                foreach (var change in prior.Files)
                    if (Hash(Resolve(root, change.Name)) != change.Hash) throw new IOException("Cần kiểm tra mã nguồn sau lần cập nhật gián đoạn.");
                File.Delete(down);
            }
            if (prior.State == "rolled-back" && File.Exists(down) && File.ReadAllText(down).Contains("VK_CRM_SOFTWARE_UPDATE")) File.Delete(down);
            if (!new[] { "completed", "rolled-back", "prepared" }.Contains(prior.State)) throw new IOException("Trạng thái cập nhật không hợp lệ.");
            // Keep each completed backup; never overwrite a customer's recovery point.
            var archived = Resolve(root, "storage/app/private/software-update-history/" + DateTime.UtcNow.ToString("yyyyMMdd-HHmmss") + "-" + Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(archived);
            foreach (var directory in new[] { "stage", "backup" }) if (Directory.Exists(Path.Combine(work, directory))) Directory.Move(Path.Combine(work, directory), Path.Combine(archived, directory));
            File.Move(journalPath, Path.Combine(archived, "journal.json"));
        }
        if (File.Exists(down)) throw new IOException("Ứng dụng đang bảo trì. Chưa thay đổi mã nguồn.");
        // An interrupted staging operation contains no published code. Use a fresh stage each time.
        if (Directory.Exists(Path.Combine(work, "stage")))
        {
            // No publishing journal means no application files were touched. Keep the
            // abandoned stage for diagnosis, but do not permanently block a retry.
            var abandoned = Resolve(root, "storage/app/private/software-update-history/abandoned-" + Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(abandoned);
            Directory.Move(Path.Combine(work, "stage"), Path.Combine(abandoned, "stage"));
        }
        progress("Đang kiểm tra bản cài và gói phần mềm…");
        var changes = Stage(root, work, package);
        if(launcherSource is not null)StageLauncher(root,work,launcherSource,changes);
        SaveJournal(journalPath, new Journal("prepared", changes));
        var stage = Path.Combine(work, "stage");
        // Prevent cached configuration from silently checking a different database.
        if (Directory.EnumerateFiles(Resolve(root, "bootstrap/cache"), "*.php").Any(p => !new[] { "services.php", "packages.php" }.Contains(Path.GetFileName(p))))
            throw new IOException("Ứng dụng có cấu hình biên dịch sẵn. Cần kỹ thuật kiểm tra trước khi cập nhật.");
        await check(root, stage);
        if (changes.Count == 0) return "Mã nguồn đã ở phiên bản này. Không thay đổi dữ liệu.";
        var envHash = Hash(Path.Combine(root, ".env"));
        foreach (var change in changes.Where(c => c.Existed))
        {
            var backup = Resolve(Path.Combine(work, "backup"), change.Name);
            Directory.CreateDirectory(Path.GetDirectoryName(backup)!);
            File.Copy(Resolve(root, change.Name), backup, false);
            if (Hash(backup) != change.Before) throw new IOException("Mã nguồn thay đổi trong lúc sao lưu. Chưa cập nhật.");
        }
        var journal = new Journal("publishing", changes);
        SaveJournal(journalPath, journal);
        Directory.CreateDirectory(Path.GetDirectoryName(down)!);
        using (var maintenance = new FileStream(down, FileMode.CreateNew, FileAccess.Write, FileShare.Read))
        { JsonSerializer.Serialize(maintenance, new { time = DateTimeOffset.UtcNow.ToUnixTimeSeconds(), retry = 60, status = 503, template = (string?)null, redirect = (string?)null, source = "VK_CRM_SOFTWARE_UPDATE" }); maintenance.Flush(true); }
        try
        {
            if(upgrade is not null)
            {
                progress("Đang sao lưu và nâng cấp cấu trúc dữ liệu…");
                await upgrade(root,stage);
            }
            progress("Đang sao lưu và cập nhật mã nguồn…");
            int index = 0;
            foreach (var change in changes)
            {
                var target = Resolve(root, change.Name);
                if ((File.Exists(target) ? Hash(target) : null) != change.Before) throw new IOException("Mã nguồn đã thay đổi trong lúc cập nhật.");
                AtomicCopy(Resolve(stage, change.Name), target);
                if (Hash(target) != change.Hash) throw new IOException("Kiểm tra mã nguồn sau cập nhật thất bại.");
                fault?.Invoke(++index);
            }
            await check(root, stage);
            foreach (var change in changes)
                if (Hash(Resolve(root, change.Name)) != change.Hash) throw new IOException("Mã nguồn đã thay đổi trong bước kiểm tra cuối.");
            if (Hash(Path.Combine(root, ".env")) != envHash) throw new IOException("Cấu hình đã thay đổi từ tiến trình khác. Cần kiểm tra trước khi mở CRM.");
            SaveJournal(journalPath, journal with { State = "completed" });
            File.Delete(down);
            return "Cập nhật thành công. Cấu hình kết nối và dữ liệu được giữ nguyên.";
        }
        catch
        {
            Restore(root, work, journal);
            if (File.Exists(down) && File.ReadAllText(down).Contains("VK_CRM_SOFTWARE_UPDATE")) File.Delete(down);
            throw;
        }
    }
    internal static void StageLauncher(string root,string work,string source,List<Change> changes)
    {
        source=Path.GetFullPath(source);NoLinks(source);
        var boundary=Path.TrimEndingDirectorySeparator(Path.GetFullPath(root))+Path.DirectorySeparatorChar;
        if(source.StartsWith(boundary,StringComparison.OrdinalIgnoreCase))throw new IOException("Chạy bộ cập nhật mới từ bên ngoài thư mục CRM.");
        var target=Resolve(root,"VK CRM.exe");
        // Fail before database changes if the old manager has not exited.
        if(File.Exists(target))using(var probe=new FileStream(target,FileMode.Open,FileAccess.ReadWrite,FileShare.None)){}
        var staged=Resolve(Path.Combine(work,"stage"),"VK CRM.exe");
        Directory.CreateDirectory(Path.GetDirectoryName(staged)!);
        File.Copy(source,staged,false);
        var expected=Hash(source);if(Hash(staged)!=expected)throw new IOException("Bộ cập nhật thay đổi trong lúc chuẩn bị.");
        var previous=File.Exists(target)?Hash(target):null;
        if(previous!=expected)changes.Add(new Change("VK CRM.exe",expected,previous is not null,previous));
    }
}
