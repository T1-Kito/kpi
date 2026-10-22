using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

static string Digest(string text) => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(text))).ToLowerInvariant();
static void Write(string root, string name, string text) { var path = Path.Combine(root, name); Directory.CreateDirectory(Path.GetDirectoryName(path)!); File.WriteAllText(path, text, new UTF8Encoding(false)); }
static string Fixture()
{
    var root = Path.Combine(Path.GetTempPath(), "VkCrmInstallerUpdateTests", Guid.NewGuid().ToString("N"));
    Directory.CreateDirectory(root);
    foreach (var name in new[] { ".env", "vendor/autoload.php", "runtime/php.exe", "runtime/php.ini", "artisan", "composer.lock", "app/version.php", "storage/app/public/customer.png", "database/customer.sqlite" }) Write(root, name, name == "composer.lock" ? "lock" : "old");
    Directory.CreateDirectory(Path.Combine(root, "bootstrap/cache"));
    Write(root, "vk-crm-installed.json", "{\"format\":1,\"database\":\"sqlite\",\"initialized\":true}");
    Write(root, "PACKAGE-MANIFEST.json", JsonSerializer.Serialize(new { files = new Dictionary<string, string> { ["app/version.php"] = Digest("old"), ["composer.lock"] = Digest("lock") } }));
    return root;
}
static MemoryStream Package(string name = "app/version.php", string? wrongHash = null, string locked = "lock")
{
    var stream = new MemoryStream();
    using (var zip = new ZipArchive(stream, ZipArchiveMode.Create, true))
    {
        var files = new Dictionary<string, string> { [name] = wrongHash ?? Digest("new"), ["composer.lock"] = Digest(locked) };
        foreach (var item in new Dictionary<string,string> { [name] = "new", ["composer.lock"] = locked, ["PACKAGE-MANIFEST.json"] = JsonSerializer.Serialize(new { files }) })
        { using var writer = new StreamWriter(zip.CreateEntry(item.Key).Open(), new UTF8Encoding(false)); writer.Write(item.Value); }
    }
    stream.Position = 0; return stream;
}
static Task Check(string root, string stage)
{
    var cache=Path.Combine(stage,"bootstrap/cache");
    Assert(Directory.Exists(cache));
    var probe=Path.Combine(cache,"test-writable.txt");
    File.WriteAllText(probe,"package-discovery");
    Assert(File.ReadAllText(probe)=="package-discovery");
    return Task.CompletedTask;
}
var passed = new List<string>();
async Task Test(string name, Func<Task> action) { await action(); passed.Add(name); Console.WriteLine("PASS " + name); }
static void Assert(bool value) { if (!value) throw new Exception("Assertion failed"); }
static async Task Reject(Func<Task> action) { try { await action(); } catch (IOException) { return; } throw new Exception("Expected refusal"); }
await Test("successful code update preserves configuration, DB, uploads and marker", async () => {
    var root = Fixture(); var protectedFiles = new[] { ".env", "database/customer.sqlite", "storage/app/public/customer.png", "vk-crm-installed.json" }.ToDictionary(x => x, x => SoftwareUpdate.Hash(Path.Combine(root, x)));
    using var package = Package(); await SoftwareUpdate.Apply(root, package, Check, _ => {});
    Assert(File.ReadAllText(Path.Combine(root, "app/version.php")) == "new");
    foreach (var p in protectedFiles) Assert(SoftwareUpdate.Hash(Path.Combine(root, p.Key)) == p.Value);
    Assert(!File.Exists(Path.Combine(root, "storage/framework/down")));
});
await Test("repeat update archives backup and does not change customer files", async () => {
    var root = Fixture(); using var one = Package(); await SoftwareUpdate.Apply(root, one, Check, _ => {}); using var two = Package(); await SoftwareUpdate.Apply(root, two, Check, _ => {});
    Assert(Directory.GetDirectories(Path.Combine(root, "storage/app/private/software-update-history")).Length == 1);
});
foreach (var name in new[] { ".env", "storage/app/public/customer.png", "database/customer.sqlite", "runtime/php.exe", "../outside.php", "app/../../outside.php", "app/name:stream.php" })
    await Test("reject forbidden path " + name, async () => { var root = Fixture(); using var package = Package(name); await Reject(() => SoftwareUpdate.Apply(root, package, Check, _ => {})); Assert(File.ReadAllText(Path.Combine(root, "app/version.php")) == "old"); });
await Test("reject bad checksum before publishing", async () => { var root = Fixture(); using var p = Package(wrongHash: Digest("wrong")); await Reject(() => SoftwareUpdate.Apply(root, p, Check, _ => {})); Assert(File.ReadAllText(Path.Combine(root, "app/version.php")) == "old"); });
await Test("reject changed dependency lock", async () => { var root = Fixture(); using var p = Package(locked: "different"); await Reject(() => SoftwareUpdate.Apply(root, p, Check, _ => {})); });
await Test("reject customer-specific code", async () => { var root = Fixture(); Write(root, "app/version.php", "custom"); using var p = Package(); await Reject(() => SoftwareUpdate.Apply(root, p, Check, _ => {})); Assert(File.ReadAllText(Path.Combine(root, "app/version.php")) == "custom"); });
await Test("compatibility failure publishes nothing", async () => { var root = Fixture(); using var p = Package(); await Reject(() => SoftwareUpdate.Apply(root, p, (_, _) => throw new IOException("SCHEMA"), _ => {})); Assert(File.ReadAllText(Path.Combine(root, "app/version.php")) == "old"); Assert(!File.Exists(Path.Combine(root, "storage/framework/down"))); });
await Test("mid-write failure restores original code and manifest", async () => { var root = Fixture(); var original = SoftwareUpdate.Hash(Path.Combine(root, "PACKAGE-MANIFEST.json")); using var p = Package(); await Reject(() => SoftwareUpdate.Apply(root, p, Check, _ => {}, _ => throw new IOException("FAULT"))); Assert(File.ReadAllText(Path.Combine(root, "app/version.php")) == "old"); Assert(SoftwareUpdate.Hash(Path.Combine(root, "PACKAGE-MANIFEST.json")) == original); Assert(!File.Exists(Path.Combine(root, "storage/framework/down"))); });
await Test("post-update check failure restores code", async () => { var root = Fixture(); int count = 0; using var p = Package(); await Reject(() => SoftwareUpdate.Apply(root, p, (_, _) => ++count == 2 ? throw new IOException("HEALTH") : Task.CompletedTask, _ => {})); Assert(File.ReadAllText(Path.Combine(root, "app/version.php")) == "old"); });
await Test("existing maintenance is not removed", async () => { var root = Fixture(); Write(root, "storage/framework/down", "customer maintenance"); using var p = Package(); await Reject(() => SoftwareUpdate.Apply(root, p, Check, _ => {})); Assert(File.ReadAllText(Path.Combine(root, "storage/framework/down")) == "customer maintenance"); });
await Test("recovery after interrupted publication restores known backups", async () => {
    var root = Fixture(); var work = Path.Combine(root, "storage/app/private/software-update"); Directory.CreateDirectory(work); using var p = Package(); var changes = SoftwareUpdate.Stage(root, work, p);
    foreach (var c in changes.Where(c => c.Existed)) Write(Path.Combine(work, "backup"), c.Name, File.ReadAllText(Path.Combine(root, c.Name)));
    SoftwareUpdate.SaveJournal(Path.Combine(work, "journal.json"), new SoftwareUpdate.Journal("publishing", changes));
    Write(root, "app/version.php", "new"); Write(root, "storage/framework/down", "{\"source\":\"VK_CRM_SOFTWARE_UPDATE\"}");
    using var retry = Package(); await Reject(() => SoftwareUpdate.Apply(root, retry, Check, _ => {})); Assert(File.ReadAllText(Path.Combine(root, "app/version.php")) == "old"); Assert(!File.Exists(Path.Combine(root, "storage/framework/down")));
});
await Test("failed staging can be retried without deleting diagnostic files", async () => {
    var root = Fixture(); using var invalid = Package(wrongHash: Digest("bad")); await Reject(() => SoftwareUpdate.Apply(root, invalid, Check, _ => {}));
    using var valid = Package(); await SoftwareUpdate.Apply(root, valid, Check, _ => {});
    Assert(File.ReadAllText(Path.Combine(root, "app/version.php")) == "new");
});
await Test("recovery journal cannot target configuration", async () => {
    var root = Fixture(); var work = Path.Combine(root, "storage/app/private/software-update"); Directory.CreateDirectory(work);
    SoftwareUpdate.SaveJournal(Path.Combine(work, "journal.json"), new SoftwareUpdate.Journal("publishing", new() { new(".env", Digest("new"), false, null) }));
    using var p = Package(); await Reject(() => SoftwareUpdate.Apply(root, p, Check, _ => {}));
    Assert(File.ReadAllText(Path.Combine(root, ".env")) == "old");
});
await Test("database upgrade hook runs in maintenance before code publication", async()=>{
    var root=Fixture();using var package=Package();bool called=false;
    await SoftwareUpdate.Apply(root,package,Check,_=>{},upgrade:(target,stage)=>{
        Assert(File.Exists(Path.Combine(target,"storage/framework/down")));
        Assert(File.ReadAllText(Path.Combine(target,"app/version.php"))=="old");called=true;return Task.CompletedTask;
    });Assert(called);
});
await Test("database upgrade refusal restores code and clears owned maintenance",async()=>{
    var root=Fixture();using var package=Package();
    await Reject(()=>SoftwareUpdate.Apply(root,package,Check,_=>{},upgrade:(_,_)=>throw new IOException("BACKUP_FAILED")));
    Assert(File.ReadAllText(Path.Combine(root,"app/version.php"))=="old");Assert(!File.Exists(Path.Combine(root,"storage/framework/down")));
});
await Test("installed manager accepts trailing separator and still rejects traversal",()=>{
    var root=Fixture();SoftwareUpdate.EnsureReadyToStart(root+Path.DirectorySeparatorChar);
    Assert(SoftwareUpdate.Resolve(root+Path.DirectorySeparatorChar,"storage/framework/down")==Path.GetFullPath(Path.Combine(root,"storage/framework/down")));
    try{SoftwareUpdate.Resolve(root+Path.DirectorySeparatorChar,"../other/file");throw new Exception("Expected refusal");}catch(IOException){}
    return Task.CompletedTask;
});
await Test("launcher upgrade is backed up and published together with application code",async()=>{
    var root=Fixture();Write(root,"VK CRM.exe","old launcher");
    var outside=Fixture();Write(outside,"Setup.exe","new trusted launcher fixture");
    using var package=Package();await SoftwareUpdate.Apply(root,package,Check,_=>{},launcherSource:Path.Combine(outside,"Setup.exe"));
    Assert(File.ReadAllText(Path.Combine(root,"VK CRM.exe"))=="new trusted launcher fixture");
    Assert(File.ReadAllText(Path.Combine(root,"storage/app/private/software-update/backup/VK CRM.exe"))=="old launcher");
});
await Test("failure after launcher publish restores previous launcher and code",async()=>{
    var root=Fixture();Write(root,"VK CRM.exe","old launcher");var outside=Fixture();Write(outside,"Setup.exe","new launcher");
    using var package=Package();await Reject(()=>SoftwareUpdate.Apply(root,package,Check,_=>{},i=>{if(i==3)throw new IOException("LAUNCHER_FAULT");},launcherSource:Path.Combine(outside,"Setup.exe")));
    Assert(File.ReadAllText(Path.Combine(root,"VK CRM.exe"))=="old launcher");Assert(File.ReadAllText(Path.Combine(root,"app/version.php"))=="old");
});
await Test("launcher cannot originate in application folder or arrive through source zip",async()=>{
    var root=Fixture();Write(root,"Setup.exe","unsafe placement");using var package=Package();
    await Reject(()=>SoftwareUpdate.Apply(root,package,Check,_=>{},launcherSource:Path.Combine(root,"Setup.exe")));
    var other=Fixture();using var invalid=Package("VK CRM.exe");await Reject(()=>SoftwareUpdate.Apply(other,invalid,Check,_=>{}));
});
await Test("locked manager blocks update before database upgrade",async()=>{
    var root=Fixture();Write(root,"VK CRM.exe","old launcher");var outside=Fixture();Write(outside,"Setup.exe","new launcher");bool upgraded=false;
    using var held=new FileStream(Path.Combine(root,"VK CRM.exe"),FileMode.Open,FileAccess.Read,FileShare.None);
    using var package=Package();
    await Reject(()=>SoftwareUpdate.Apply(root,package,Check,_=>{},upgrade:(_,_)=>{upgraded=true;return Task.CompletedTask;},launcherSource:Path.Combine(outside,"Setup.exe")));
    Assert(!upgraded);Assert(File.ReadAllText(Path.Combine(root,"app/version.php"))=="old");
});
Console.WriteLine($"TOTAL {passed.Count} PASS. Synthetic fixtures only; no customer database was accessed.");
