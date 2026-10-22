using System.IO.Compression;
using System.Text.Json;

namespace VkCrmUpdateDemo;

static class DemoTests
{
    public static int Run(string reportPath)
    {
        var results = new List<string>();
        try
        {
            void Check(bool condition, string message) { if (!condition) throw new Exception(message); results.Add("PASS " + message); }
            void Reject(byte[] package, string label)
            {
                var fixture = DemoUpdate.CreateFixture();
                var expected = DemoUpdate.Fingerprints(fixture);
                var rejected = false;
                try { DemoUpdate.Apply(fixture, package); } catch (IOException) { rejected = true; }
                Check(rejected, label);
                Check(DemoUpdate.Version(fixture) == "1.0" && expected.All(pair => DemoUpdate.Fingerprints(fixture)[pair.Key] == pair.Value), label + " leaves state unchanged");
            }
            var root = DemoUpdate.CreateFixture(); var before = DemoUpdate.Fingerprints(root);
            DemoUpdate.Apply(root, DemoUpdate.Package());
            Check(DemoUpdate.Version(root) == "1.1", "code version advances");
            Check(File.ReadAllText(Path.Combine(root, "app/demo-version.txt")).Contains("1.1"), "updated code is installed");
            Check(before.All(pair => DemoUpdate.Fingerprints(root)[pair.Key] == pair.Value), "SQL configuration, accounts, customers and images preserved byte-for-byte");
            var repeated = false;
            try { DemoUpdate.Apply(root, DemoUpdate.Package()); } catch (IOException) { repeated = true; }
            Check(repeated, "repeated update is rejected");
            Reject(DemoUpdate.Package(new() { [".env"] = "changed" }), "connection configuration overwrite blocked");
            Reject(DemoUpdate.Package(new() { ["database/customers.json"] = "changed" }), "database overwrite blocked");
            Reject(DemoUpdate.Package(new() { ["storage/app/public/photo.txt"] = "changed" }), "uploaded file overwrite blocked");
            Reject(DemoUpdate.Package(new() { ["app/../../.env"] = "changed" }), "path traversal blocked");
            var damaged = DemoUpdate.Package();
            using (var stream = new MemoryStream())
            {
                stream.Write(damaged); stream.Position = 0;
                using (var zip = new ZipArchive(stream, ZipArchiveMode.Update, true))
                { var entry = zip.GetEntry("app/demo-version.txt")!; entry.Delete(); using var writer = new StreamWriter(zip.CreateEntry("app/demo-version.txt").Open()); writer.Write("tampered"); }
                damaged = stream.ToArray();
            }
            Reject(damaged, "checksum mismatch blocked");
            var failedRoot = DemoUpdate.CreateFixture(); var oldCode = File.ReadAllText(Path.Combine(failedRoot, "app/demo-version.txt"));
            var rolledBack = false;
            try { DemoUpdate.Apply(failedRoot, DemoUpdate.Package(), afterWrite: _ => throw new IOException("Simulated write failure")); }
            catch (IOException) { rolledBack = true; }
            Check(rolledBack && DemoUpdate.Version(failedRoot) == "1.0" && File.ReadAllText(Path.Combine(failedRoot, "app/demo-version.txt")) == oldCode, "partial file update rolls back code and version");
            var outside = false;
            try { DemoUpdate.Apply(AppContext.BaseDirectory, DemoUpdate.Package()); } catch (IOException) { outside = true; }
            Check(outside, "non-demo installation cannot be updated");
            results.Add("All fixtures are synthetic and retained under the OS temporary VkCrmUpdateDemo directory.");
            Directory.CreateDirectory(Path.GetDirectoryName(Path.GetFullPath(reportPath))!);
            File.WriteAllLines(reportPath, results); return 0;
        }
        catch (Exception ex) { results.Add("FAIL " + ex); File.WriteAllLines(reportPath, results); return 1; }
    }
}
