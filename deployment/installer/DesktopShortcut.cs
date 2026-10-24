using System.Reflection;
using System.Runtime.InteropServices;
using System.Security.Cryptography;

sealed partial class SetupForm
{
    internal static void TestDesktopShortcut()
    {
        var root=Path.Combine(Path.GetTempPath(),"VKCRM-Shortcut-Test-"+Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        try{
            var install=Path.Combine(root,"Installed App");var desktop=Path.Combine(root,"Desktop");
            Directory.CreateDirectory(install);Directory.CreateDirectory(desktop);
            File.WriteAllText(Path.Combine(install,"VK CRM.exe"),"Not executable: shortcut test fixture only");
            File.WriteAllText(Path.Combine(install,InstalledMarker),"{}");
            var unrelated=Path.Combine(desktop,"VK CRM.lnk");
            object? shell=null;object? link=null;
            try{
                shell=Activator.CreateInstance(Type.GetTypeFromProgID("WScript.Shell")!);
                link=((dynamic)shell!).CreateShortcut(unrelated);((dynamic)link).TargetPath=Path.Combine(root,"other.exe");((dynamic)link).Save();
            }finally{if(link is not null)Marshal.FinalReleaseComObject(link);if(shell is not null)Marshal.FinalReleaseComObject(shell);}
            var originalHash=SHA256.HashData(File.ReadAllBytes(unrelated));
            var shortcut=CreateDesktopShortcut(install,desktop);
            if(Path.GetFileName(shortcut)!="VK CRM (2).lnk")throw new IOException("Shortcut name collision failed");
            if(CreateDesktopShortcut(install,desktop)!=shortcut)throw new IOException("Duplicate shortcut created");
            if(!originalHash.SequenceEqual(SHA256.HashData(File.ReadAllBytes(unrelated))))throw new IOException("Unrelated shortcut changed");
            shell=null;link=null;
            try{
                shell=Activator.CreateInstance(Type.GetTypeFromProgID("WScript.Shell")!);link=((dynamic)shell!).CreateShortcut(shortcut);
                if((string)((dynamic)link).Arguments!="--open"||(string)((dynamic)link).TargetPath!=Path.Combine(install,"VK CRM.exe")||!((string)((dynamic)link).IconLocation).Contains("VK-CRM-no-black-border.ico"))throw new IOException("Incorrect shortcut properties");
            }finally{if(link is not null)Marshal.FinalReleaseComObject(link);if(shell is not null)Marshal.FinalReleaseComObject(shell);}
            using var icon=new Icon(Path.Combine(install,"VK-CRM-no-black-border.ico"));
            if(icon.Width<16||icon.Height<16)throw new IOException("Invalid embedded icon");
        }finally{
            // This exact UUID directory is created above for this test, never a user-selected path.
            Directory.Delete(root,true);
        }
    }

    static string CreateDesktopShortcut(string target,string? desktopOverride=null)
    {
        var launcher=Path.GetFullPath(Path.Combine(target,"VK CRM.exe"));
        if(!File.Exists(launcher)||!File.Exists(Path.Combine(target,InstalledMarker)))throw new IOException("Ứng dụng chưa được cài đặt đầy đủ.");
        var desktop=desktopOverride??Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory);
        if(string.IsNullOrWhiteSpace(desktop)||!Directory.Exists(desktop))throw new IOException("Không tìm thấy thư mục Desktop.");
        var iconPath=Path.Combine(target,"VK-CRM-no-black-border.ico");
        using(var resource=Assembly.GetExecutingAssembly().GetManifestResourceStream("VK-CRM.ico")??throw new IOException("Thiếu biểu tượng ứng dụng.")){
            if(!File.Exists(iconPath)){using var icon=new FileStream(iconPath,FileMode.CreateNew);resource.CopyTo(icon);}
            else {using var current=File.OpenRead(iconPath);if(!SHA256.HashData(current).SequenceEqual(SHA256.HashData(resource)))throw new IOException("Biểu tượng hiện có không thuộc bản cài này.");}
        }
        object? shell=null;object? link=null;
        try{
            shell=Activator.CreateInstance(Type.GetTypeFromProgID("WScript.Shell")??throw new IOException("Windows chưa hỗ trợ tạo lối tắt."));
            for(var index=0;index<100;index++){
                var name=index==0?"VK CRM.lnk":$"VK CRM ({index+1}).lnk";
                var shortcutPath=Path.Combine(desktop,name);
                link=((dynamic)shell!).CreateShortcut(shortcutPath);
                if(File.Exists(shortcutPath)){
                    var existingTarget=(string)((dynamic)link).TargetPath;
                    if(string.Equals(existingTarget,launcher,StringComparison.OrdinalIgnoreCase)){
                        ((dynamic)link).IconLocation=iconPath+",0";
                        ((dynamic)link).Save();
                        return shortcutPath;
                    }
                    Marshal.FinalReleaseComObject(link);link=null;continue;
                }
                ((dynamic)link).TargetPath=launcher;
                ((dynamic)link).Arguments="--open";
                ((dynamic)link).WorkingDirectory=target;
                ((dynamic)link).IconLocation=iconPath+",0";
                ((dynamic)link).Description="VK CRM — Quản lý khách hàng và nhân sự";
                ((dynamic)link).Save();
                return shortcutPath;
            }
            throw new IOException("Đã có nhiều biểu tượng VK CRM trên Desktop.");
        }finally{if(link is not null)Marshal.FinalReleaseComObject(link);if(shell is not null)Marshal.FinalReleaseComObject(shell);}
    }
}
