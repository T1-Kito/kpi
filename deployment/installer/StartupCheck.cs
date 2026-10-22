using System.Diagnostics;
using Microsoft.Win32;

internal sealed record StartupIssue(string Title,string Message,string? Url=null);

internal static class StartupCheck
{
    internal const string OdbcUrl="https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server";
    internal const string VcUrl="https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist";
    internal static bool HasOdbc()
    {
        using var registry=RegistryKey.OpenBaseKey(RegistryHive.LocalMachine,RegistryView.Registry64);
        using var drivers=registry.OpenSubKey(@"SOFTWARE\ODBC\ODBCINST.INI\ODBC Drivers");
        return string.Equals(drivers?.GetValue("ODBC Driver 18 for SQL Server") as string,"Installed",StringComparison.OrdinalIgnoreCase);
    }

    internal static async Task<StartupIssue?> Probe(string target)
    {
        string[] required={"runtime/php.exe","runtime/php.ini","vendor/autoload.php","artisan","initialize-uat.php"};
        if(required.Any(file=>!File.Exists(Path.Combine(target,file))))
            return new("Bộ ứng dụng chưa đầy đủ","Không cần chọn PHP hoặc cài Composer. Hãy giải nén bản cài vào một thư mục mới. Giữ nguyên thư mục dữ liệu cũ để tránh mất dữ liệu.");
        var info=new ProcessStartInfo(Path.Combine(target,"runtime","php.exe")){WorkingDirectory=target,UseShellExecute=false,CreateNoWindow=true,RedirectStandardOutput=true,RedirectStandardError=true};
        info.ArgumentList.Add("-c");info.ArgumentList.Add(Path.Combine(target,"runtime","php.ini"));
        info.ArgumentList.Add("-d");info.ArgumentList.Add("extension_dir="+Path.Combine(target,"runtime","ext"));
        info.ArgumentList.Add("-r");info.ArgumentList.Add("foreach(['pdo_sqlite','mbstring','openssl','fileinfo','zip','bcmath'] as $e) { if(!extension_loaded($e)) {fwrite(STDERR, $e.' ');exit(2);} } echo 'VK_RUNTIME_OK';");
        ProcessText.UseUtf8(info);
        using var process=Process.Start(info) ?? throw new IOException("Không mở được bộ ứng dụng.");
        var output=process.StandardOutput.ReadToEndAsync();var error=process.StandardError.ReadToEndAsync();
        using var timeout=new CancellationTokenSource(TimeSpan.FromSeconds(20));
        try{await process.WaitForExitAsync(timeout.Token);}catch(OperationCanceledException){process.Kill(true);return new("Kiểm tra quá thời gian","Bộ ứng dụng chưa phản hồi. Kiểm tra lại sau; không cần cài lại cơ sở dữ liệu.");}
        var stdout=await output;var stderr=await error;
        if(process.ExitCode==unchecked((int)0xC0000135))
            return new("Thiếu thành phần chạy ứng dụng","Windows báo thiếu thư viện hệ thống. Cài Microsoft Visual C++ Redistributable x64 từ trang Microsoft rồi kiểm tra lại. Không cần tải PHP hoặc Composer.",VcUrl);
        if(process.ExitCode!=0 || stdout!="VK_RUNTIME_OK" || !string.IsNullOrWhiteSpace(stderr))
            return new("Bộ ứng dụng chưa chạy được","Không phải lỗi tài khoản hay database. Bộ ứng dụng có thể thiếu hoặc không tương thích. Hãy gửi thông báo này cho kỹ thuật; không xóa dữ liệu hiện có.\nMã kiểm tra: "+process.ExitCode);
        return null;
    }
}
