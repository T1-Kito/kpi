using System.Diagnostics;
using System.Reflection;
using System.Security.AccessControl;
using System.Security.Principal;
using System.Text.Json;

sealed partial class SetupForm
{
    static void PrepareInstalledSqlRuntime(string target)
    {
        // Restrict this new trial install before any database password is written.
        var directory=new DirectoryInfo(target);
        var acl=new DirectorySecurity();acl.SetAccessRuleProtection(true,false);
        foreach(var sid in new[]{WindowsIdentity.GetCurrent().User!,new SecurityIdentifier(WellKnownSidType.LocalSystemSid,null),new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid,null)})
            acl.AddAccessRule(new FileSystemAccessRule(sid,FileSystemRights.FullControl,InheritanceFlags.ContainerInherit|InheritanceFlags.ObjectInherit,PropagationFlags.None,AccessControlType.Allow));
        directory.SetAccessControl(acl);
        foreach(var name in new[]{"initialize-sqlserver.php","sql-install-settings.php","sql-install-diagnostics.php","sql-install-preflight.php","php_pdo_sqlsrv_82_ts_x64.dll"}){
            var outputPath=Path.Combine(target,name.EndsWith(".dll")?"runtime/ext/"+name:name);
            using var input=Assembly.GetExecutingAssembly().GetManifestResourceStream(name)??throw new IOException("Thiếu bộ chạy SQL Server.");
            using var output=new FileStream(outputPath,FileMode.CreateNew);input.CopyTo(output);
        }
        File.AppendAllText(Path.Combine(target,"runtime/php.ini"),"\nextension=php_pdo_sqlsrv_82_ts_x64.dll\n");
    }

    async Task InitializeSqlTrial()
    {
        if(!sqlSetupConsent.Checked&&!sqlTrialEnabled){status.Text="Vui lòng xác nhận cơ sở dữ liệu trước khi thiết lập.";return;}
        var target=Path.GetFullPath(destination.Text.Trim());
        try{
            if(password.Text.Length<8||!email.Text.Contains('@')||string.IsNullOrWhiteSpace(company.Text))throw new Exception("Nhập doanh nghiệp, email và mật khẩu Admin ít nhất 8 ký tự.");
            var info=new ProcessStartInfo(Path.Combine(target,"runtime/php.exe")){WorkingDirectory=target,UseShellExecute=false,CreateNoWindow=true,RedirectStandardInput=true,RedirectStandardOutput=true,RedirectStandardError=true,StandardInputEncoding=new System.Text.UTF8Encoding(false),StandardOutputEncoding=System.Text.Encoding.UTF8,StandardErrorEncoding=System.Text.Encoding.UTF8};
            foreach(var arg in new[]{"-c",Path.Combine(target,"runtime/php.ini"),"-d","extension_dir="+Path.Combine(target,"runtime/ext"),Path.Combine(target,"initialize-sqlserver.php")})info.ArgumentList.Add(arg);
            ProcessText.UseUtf8(info);
            using var process=Process.Start(info)??throw new IOException("Không chạy được trình cài SQL.");
            await process.StandardInput.WriteAsync(JsonSerializer.Serialize(new{server=sqlServerName.Text.Trim(),database=sqlDatabaseName.Text.Trim(),auth=sqlAuth.SelectedIndex==1?"windows":"sql",username=sqlUsername.Text,sql_password=sqlPassword.Text,trust_certificate=sqlTrust.Checked,company=company.Text,email=email.Text,password=password.Text,confirm_empty_trial=true,resume_empty_repository=sqlResumeEmpty.Checked,database_mode=sqlDatabaseMode.SelectedIndex==1?"create":"existing"}));process.StandardInput.Close();
            var output=process.StandardOutput.ReadToEndAsync();var error=process.StandardError.ReadToEndAsync();
            using var timeout=new CancellationTokenSource(TimeSpan.FromMinutes(5));
            try{await process.WaitForExitAsync(timeout.Token);}catch(OperationCanceledException){process.Kill(true);await process.WaitForExitAsync();throw new Exception("Quá thời gian tạo bảng SQL. Giữ nguyên database/thư mục để kiểm tra; không tự chạy lại.");}
            var result=await output;await error;
            var resultLines=result.Split(new[]{'\r','\n'},StringSplitOptions.RemoveEmptyEntries);
            var diagnosticLine=resultLines.FirstOrDefault(line=>line.StartsWith("SQL_DIAGNOSTIC:",StringComparison.Ordinal));
            var diagnosticSummary="";
            if(diagnosticLine is not null){
                using var diagnostic=JsonDocument.Parse(diagnosticLine[15..]);
                var fields=diagnostic.RootElement;
                diagnosticSummary="\nMigration: "+fields.GetProperty("migration").ToString()+"\nSQLSTATE: "+fields.GetProperty("sqlstate").ToString()+" · Mã SQL: "+fields.GetProperty("native").ToString()+" · "+fields.GetProperty("reason").ToString();
                log.AppendText(diagnosticSummary+Environment.NewLine);
            }
            result=resultLines.FirstOrDefault()??"";
            // Suppress framework/driver messages; SQL exceptions can contain connection secrets.
            if(result.Trim()=="SQL_CREATE_PERMISSION")throw new Exception("Tài khoản chưa có quyền tạo cơ sở dữ liệu. Liên hệ quản trị viên để chuẩn bị cơ sở dữ liệu và cấp quyền, sau đó chọn Dùng database trống có sẵn.");
            if(result.Trim()=="SQL_DATABASE_EXISTS")throw new Exception("Tên cơ sở dữ liệu đã tồn tại. Hệ thống không tạo đè. Nếu cơ sở dữ liệu này đang trống, chọn Dùng database trống có sẵn.");
            var stages=new Dictionary<string,string>{{"INPUT","kiểm tra thông tin Admin/cấu hình"},{"CONNECT","kết nối SQL"},{"OWNER","kiểm tra quyền database"},{"EMPTY","kiểm tra database trống"},{"LOCK","khóa phiên cài đặt"},{"FILES","ghi thư mục/cấu hình"},{"BOOTSTRAP","nạp ứng dụng"},{"SCHEMA","tạo bảng CRM"},{"ADMIN","tạo tài khoản Admin"}};
            if(result.Trim().StartsWith("SQL_STAGE_",StringComparison.Ordinal)&&stages.TryGetValue(result.Trim()[10..],out var description))throw new Exception("Cài SQL dừng ở bước "+description+". Mã: "+result.Trim()+diagnosticSummary+"\nGiữ nguyên database và thư mục để kiểm tra. Không tự cài đè.");
            if(process.ExitCode!=0||result.Trim()!="SQL_INSTALL_OK")throw new Exception("Cài SQL chưa thành công. Kiểm tra database trống, quyền, TLS và tương thích bảng. Không xóa dữ liệu hoặc cài lại đè.");
            databaseInitialized=true;password.Clear();sqlPassword.Clear();SaveInstalledManager(target);
            status.Text="Đã thiết lập cơ sở dữ liệu và tài khoản quản trị. Chọn Khởi động để mở VK CRM.";
        }catch(Exception ex){status.Text=ex.Message;}
    }
}
