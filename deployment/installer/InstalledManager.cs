using System.Text.Json;

sealed partial class SetupForm
{
    const string InstalledMarker="vk-crm-installed.json";

    void SaveInstalledManager(string target)
    {
        if(!databaseInitialized)throw new InvalidOperationException("Dữ liệu chưa được khởi tạo thành công.");
        RequireRuntime(target);
        if(!File.Exists(Path.Combine(target,".env"))||(databaseType.SelectedIndex==0&&!File.Exists(Path.Combine(target,"database","uat.sqlite"))))throw new IOException("Thiếu cấu hình hoặc dữ liệu sau khởi tạo.");
        var launcher=Path.Combine(target,"VK CRM.exe");
        var executable=Environment.ProcessPath??throw new IOException("Không xác định được chương trình cài đặt.");
        // Never replace an existing executable or configuration.
        if(!string.Equals(Path.GetFullPath(executable),Path.GetFullPath(launcher),StringComparison.OrdinalIgnoreCase))File.Copy(executable,launcher,false);
        using(var marker=new FileStream(Path.Combine(target,InstalledMarker),FileMode.CreateNew,FileAccess.Write))
            JsonSerializer.Serialize(marker,new {format=1,database=databaseType.SelectedIndex==1?"sqlsrv":"sqlite",initialized=true,release_version=SoftwareUpdate.Version});
        try{CreateDesktopShortcut(target);}
        catch(Exception){log.AppendText("Đã cài ứng dụng nhưng chưa tạo được biểu tượng Desktop. Mở VK CRM.exe trong thư mục cài đặt."+Environment.NewLine);}
    }

    void TryResumeInstalledManager()
    {
        // Resolve only beside this executable, never a stale global install path.
        var target=Path.GetFullPath(AppContext.BaseDirectory);
        var marker=Path.Combine(target,InstalledMarker);
        if(!File.Exists(marker))return;
        try
        {
            using var json=JsonDocument.Parse(File.ReadAllText(marker));
            var database=json.RootElement.GetProperty("database").GetString();
            if(json.RootElement.GetProperty("format").GetInt32()!=1||(database!="sqlite"&&database!="sqlsrv")||!json.RootElement.GetProperty("initialized").GetBoolean())throw new IOException("Cấu hình trình quản lý không hợp lệ.");
            RequireRuntime(target);
            if(!File.Exists(Path.Combine(target,".env"))||(database=="sqlite"&&!File.Exists(Path.Combine(target,"database","uat.sqlite"))))throw new IOException("Thiếu cấu hình hoặc database. Không tự tạo lại để tránh mất dữ liệu.");
            destination.Text=target;databaseType.SelectedIndex=database=="sqlsrv"?1:0;databaseInitialized=true;step=4;
            status.Text="Đã nạp bản cài. Bấm Khởi động để kiểm tra và mở CRM.";
        }
        catch(Exception ex)
        {
            MessageBox.Show(this,"Không mở lại được bản cài: "+ex.Message+"\nGiữ nguyên thư mục và dữ liệu để kiểm tra.","VK CRM",MessageBoxButtons.OK,MessageBoxIcon.Warning);
        }
    }
}
