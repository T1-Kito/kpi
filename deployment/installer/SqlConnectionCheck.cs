using System.Diagnostics;
using System.IO.Compression;
using System.Reflection;
using System.Security.Cryptography;
using System.Text.Json;

sealed partial class SetupForm
{
    readonly ComboBox sqlAuth=new(){DropDownStyle=ComboBoxStyle.DropDownList};
    readonly CheckBox sqlTrust=new(){Text="Tin cậy chứng chỉ máy chủ (chỉ dùng trong mạng nội bộ)",AutoSize=true};
    readonly Label sqlResult=new(){AutoSize=true,MaximumSize=new Size(570,0),ForeColor=Color.FromArgb(71,85,105)};

    async Task CheckSqlConnection(Button button)
    {
        if(wizardBusy)return;
        wizardBusy=true;
        button.Enabled=false;wizardNext.Enabled=false;wizardBack.Enabled=false;
        sqlResult.Text="Đang kiểm tra kết nối…";
        try
        {
            if(string.IsNullOrWhiteSpace(sqlServerName.Text)||string.IsNullOrWhiteSpace(sqlDatabaseName.Text))throw new Exception("Nhập máy chủ và tên database trước khi kiểm tra.");
            if(!StartupCheck.HasOdbc()){ShowPrerequisite("Thiếu Microsoft ODBC Driver 18","Tải và cài driver từ Microsoft, sau đó kiểm tra lại.",StartupCheck.OdbcUrl);return;}
            var checkingNewDatabase=sqlDatabaseMode.SelectedIndex==1;
            var settings=new {server=sqlServerName.Text.Trim(),database=checkingNewDatabase?"master":sqlDatabaseName.Text.Trim(),auth=sqlAuth.SelectedIndex==1?"windows":"sql",username=sqlUsername.Text,password=sqlPassword.Text,trust_certificate=sqlTrust.Checked};
            var root=Path.Combine(Path.GetTempPath(),"VKCRM-SqlCheck-"+Guid.NewGuid().ToString("N"));
            // Disposable isolated runtime: never use or change the running CRM folder.
            Directory.CreateDirectory(root);
            try
            {
                await Task.Run(()=>PrepareSqlProbe(root));
                var info=new ProcessStartInfo(Path.Combine(root,"runtime","php.exe")){WorkingDirectory=root,UseShellExecute=false,CreateNoWindow=true,RedirectStandardInput=true,RedirectStandardOutput=true,RedirectStandardError=true,StandardInputEncoding=new System.Text.UTF8Encoding(false),StandardOutputEncoding=System.Text.Encoding.UTF8,StandardErrorEncoding=System.Text.Encoding.UTF8};
                foreach(var arg in new[]{"-n","-d","extension="+Path.Combine(root,"php_pdo_sqlsrv_82_ts_x64.dll"),Path.Combine(root,"sqlserver-connect.php")})info.ArgumentList.Add(arg);
                ProcessText.UseUtf8(info);
                using var process=Process.Start(info)??throw new Exception("Không khởi động được bộ kiểm tra.");
                await process.StandardInput.WriteAsync(JsonSerializer.Serialize(settings));process.StandardInput.Close();
                var output=process.StandardOutput.ReadToEndAsync();var errors=process.StandardError.ReadToEndAsync();
                using var timeout=new CancellationTokenSource(TimeSpan.FromSeconds(20));
                try{await process.WaitForExitAsync(timeout.Token);}catch(OperationCanceledException){process.Kill(true);await process.WaitForExitAsync();throw new Exception("Kết nối quá thời gian. Kiểm tra máy chủ và firewall rồi thử lại.");}
                var stdout=await output;var stderr=await errors;
                if(process.ExitCode==unchecked((int)0xC0000135)){ShowPrerequisite("Thiếu thư viện hệ thống","Cài Visual C++ Redistributable x64 rồi kiểm tra lại.",StartupCheck.VcUrl);return;}
                if(!string.IsNullOrWhiteSpace(stderr))throw new Exception("Không nạp được trình kết nối. Kiểm tra ODBC và Visual C++ x64 trên máy này.");
                using var response=JsonDocument.Parse(stdout);
                sqlResult.Text=response.RootElement.GetProperty("message").GetString();
                if(checkingNewDatabase&&response.RootElement.GetProperty("ok").GetBoolean())sqlResult.Text="Kết nối máy chủ đạt. Chưa tạo database; quyền tạo và tên database sẽ được kiểm tra khi bạn xác nhận cài đặt.";
                sqlResult.ForeColor=response.RootElement.GetProperty("ok").GetBoolean()?Color.FromArgb(21,128,61):Color.FromArgb(180,83,9);
            }
            finally
            {
                // Exact, uniquely created temporary directory only. Never delete a selected install path.
                try{Directory.Delete(root,true);}catch(IOException){}catch(UnauthorizedAccessException){}
            }
        }
        catch(Exception ex){sqlResult.Text=ex is JsonException?"Bộ kiểm tra chưa phản hồi đúng định dạng. Liên hệ kỹ thuật.":ex.Message;sqlResult.ForeColor=Color.FromArgb(180,83,9);}
        finally{wizardBusy=false;button.Enabled=true;wizardNext.Enabled=true;wizardBack.Enabled=true;}
    }

    static void PrepareSqlProbe(string root)
    {
        var assembly=Assembly.GetExecutingAssembly();
        using(var stream=assembly.GetManifestResourceStream("portable.zip")!)
        using(var checksum=new StreamReader(assembly.GetManifestResourceStream("portable.sha256")!))
        {
            if(!Convert.ToHexString(SHA256.HashData(stream)).Equals(checksum.ReadToEnd().Trim(),StringComparison.OrdinalIgnoreCase))throw new IOException("Bộ ứng dụng sai checksum.");
            stream.Position=0;using var zip=new ZipArchive(stream,ZipArchiveMode.Read);
            foreach(var entry in zip.Entries)
            {
                var name=entry.FullName.Replace('\\','/');
                if(!name.StartsWith("runtime/",StringComparison.Ordinal)||name.EndsWith('/')||name.Count(c=>c=='/')!=1)continue;
                if(!name.EndsWith(".dll",StringComparison.OrdinalIgnoreCase)&&name!="runtime/php.exe")continue;
                var path=Path.GetFullPath(Path.Combine(root,name));
                if(!path.StartsWith(root+Path.DirectorySeparatorChar,StringComparison.OrdinalIgnoreCase))throw new IOException("Đường dẫn không hợp lệ.");
                Directory.CreateDirectory(Path.GetDirectoryName(path)!);entry.ExtractToFile(path);
            }
        }
        foreach(var name in new[]{"php_pdo_sqlsrv_82_ts_x64.dll","sqlserver-connect.php"})
        {
            using var input=assembly.GetManifestResourceStream(name)??throw new IOException("Thiếu thành phần SQL Server.");
            using var output=new FileStream(Path.Combine(root,name),FileMode.CreateNew);input.CopyTo(output);
        }
    }
}
