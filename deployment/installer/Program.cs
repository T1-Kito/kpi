using System.Diagnostics;
using System.IO.Compression;
using System.Reflection;
using System.Security.Cryptography;
using System.Text.Json;

internal static class Program
{
    [STAThread]
    private static int Main(string[] args)
    {
        ApplicationConfiguration.Initialize();
        if(args.Length==2 && args[0]=="--update") { Application.Run(new UpdateForm(args[1])); return 0; }
        if(args.Length==4 && args[0]=="--update" && args[2]=="--wait-for" && int.TryParse(args[3],out var managerPid) && managerPid>0) { Application.Run(new UpdateForm(args[1],managerPid));return 0; }
        if(args.Length==2&&args[0]=="--test-utf8")
        {
            try{ProcessText.TestRoundTrip(args[1]);return 0;}catch{return 1;}
        }
        if(args.Length==1&&args[0]=="--self-test")
        {
            try{using var form=new SetupForm();form.TestWizard();return 0;}
            catch{return 1;}
        }
        Application.Run(new SetupForm(args.Contains("--sql-trial"),args.Contains("--open")));
        return 0;
    }
}

sealed partial class SetupForm : Form
{
    readonly TextBox destination = new() { Dock = DockStyle.Top, Text = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "VK CRM") };
    readonly Button install = new() { Text = "Giải nén bản thử", AutoSize = true };
    readonly Label status = new() { AutoSize = true, MaximumSize = new Size(650, 0) };
    readonly TextBox php = new() { Width=650, PlaceholderText="Chọn php.exe trên máy này" };
    readonly TextBox composer = new() { Width=650, PlaceholderText="Chọn composer.phar (không phải composer.bat)" };
    readonly TextBox log = new() { Width=650, Height=150, Multiline=true, ReadOnly=true, ScrollBars=ScrollBars.Vertical };
    readonly Button prepare = new() { Text="3. Kiểm tra và cài thư viện", AutoSize=true };
    readonly TextBox company = new() { Width=650,PlaceholderText="Tên doanh nghiệp" };
    readonly TextBox email = new() { Width=650,PlaceholderText="Email Admin" };
    readonly TextBox operatorEmail = new() { Width=650,PlaceholderText="Email Admin thường" };
    readonly TextBox operatorPassword = new() { Width=650,PlaceholderText="Mật khẩu Admin thường riêng, ít nhất 12 ký tự",UseSystemPasswordChar=true };
    readonly TextBox password = new() { Width=650,PlaceholderText="Mật khẩu Admin riêng, ít nhất 12 ký tự",UseSystemPasswordChar=true };
    readonly Button initialize = new() { Text="Tạo SQLite UAT riêng",AutoSize=true };
    readonly Button start = new() { Text="Mở CRM thử tại máy này",AutoSize=true };
    readonly Button stop = new() { Text="Dừng CRM thử",AutoSize=true };
    Process? server;
    bool databaseInitialized;
    readonly ComboBox databaseType=new(){DropDownStyle=ComboBoxStyle.DropDownList,Width=230};
    readonly Label connectionSummary=new(){Text="SQLite · Dữ liệu tại máy này",AutoSize=true,ForeColor=Color.FromArgb(100,116,139)};
    readonly bool sqlTrialEnabled;
    public SetupForm(bool enableSqlTrial=false,bool openInstalled=false)
    {
        sqlTrialEnabled=enableSqlTrial;
        Text = "VK CRM — Quản lý máy chủ · Bản thử";
        Size = new Size(820, 650); MinimumSize = Size;
        StartPosition = FormStartPosition.CenterScreen;
        Font = new Font("Segoe UI", 10);
        BackColor=Color.FromArgb(244,247,251);
        var panel = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.TopDown, WrapContents = false, Padding = new Padding(28), AutoScroll = true };
        panel.Controls.Add(new Label { Text="VK CRM",Font=new Font("Segoe UI",23,FontStyle.Bold),ForeColor=Color.FromArgb(15,23,42),AutoSize=true });
        panel.Controls.Add(new Label { Text="Quản lý máy chủ",AutoSize=true,ForeColor=Color.FromArgb(100,116,139),Margin=new Padding(0,0,0,20) });
        var card=new FlowLayoutPanel { Width=730,Height=160,BackColor=Color.White,Padding=new Padding(20),FlowDirection=FlowDirection.TopDown,WrapContents=false };
        card.Controls.Add(new Label { Text="CƠ SỞ DỮ LIỆU",AutoSize=true,ForeColor=Color.FromArgb(100,116,139) });
        databaseType.Items.AddRange(new object[]{"SQLite — dùng thử tại máy","SQL Server"});databaseType.SelectedIndex=0;
        card.Controls.Add(databaseType);card.Controls.Add(connectionSummary);
        var configure=new Button { Text="Cấu hình kết nối",AutoSize=true,FlatStyle=FlatStyle.Flat };
        configure.Click+=(_,_)=>ConfigureDatabase();card.Controls.Add(configure);panel.Controls.Add(card);
        var actions=new FlowLayoutPanel { Width=730,Height=65,Padding=new Padding(0,14,0,0) };
        start.Text="Khởi động máy chủ";stop.Text="Dừng máy chủ";
        start.BackColor=Color.FromArgb(37,99,235);start.ForeColor=Color.White;start.FlatStyle=FlatStyle.Flat;
        var open=new Button { Text="Mở phần mềm",AutoSize=true };
        open.Click+=(_,_)=>{if(server is not {HasExited:false}){status.Text="Máy chủ chưa chạy. Bấm Khởi động máy chủ trước.";return;}Process.Start(new ProcessStartInfo("http://127.0.0.1:8099"){UseShellExecute=true});};
        actions.Controls.Add(start);actions.Controls.Add(stop);actions.Controls.Add(open);panel.Controls.Add(actions);
        status.Text="Máy chủ chưa chạy";status.ForeColor=Color.FromArgb(51,65,85);panel.Controls.Add(status);
        var detail=new Button { Text="Xem nhật ký",AutoSize=true,Margin=new Padding(0,18,0,0) };
        log.Visible=false;log.Height=110;detail.Click+=(_,_)=>log.Visible=!log.Visible;panel.Controls.Add(detail);panel.Controls.Add(log);
        panel.Controls.Add(new Label { Text="Bản thử localhost · SQL Server chưa nghiệm thu · Không dùng dữ liệu khách thật",AutoSize=true,ForeColor=Color.FromArgb(100,116,139),Margin=new Padding(0,18,0,0) });
        databaseType.SelectedIndexChanged+=(_,_)=>{connectionSummary.Text=databaseType.SelectedIndex==0?"SQLite · Dữ liệu tại máy này":"SQL Server · Chưa kiểm tra kết nối";};
        initialize.Click+=async(_,_)=>await InitializeUat();
        start.Click+=async(_,_)=>{
            start.Enabled=false;
            try {
            if(databaseType.SelectedIndex==1){
                if(!StartupCheck.HasOdbc()) ShowPrerequisite("Thiếu trình kết nối SQL Server", "Máy này chưa có Microsoft ODBC Driver 18. Tải và cài từ Microsoft, sau đó bấm Kiểm tra lại.\n\nLưu ý: cài driver chưa có nghĩa bản thử này đã hỗ trợ chạy SQL Server.", StartupCheck.OdbcUrl);
                else status.Text="Đã có ODBC. Chức năng chạy CRM bằng SQL Server chưa sẵn sàng trong bản thử này.";
                return;
            }
            var target=Path.GetFullPath(destination.Text);
            if(!File.Exists(Path.Combine(target,"runtime","php.exe"))) {
                status.Text="Đang chuẩn bị ứng dụng lần đầu…";await Task.Yield();await Extract();
                if(!File.Exists(Path.Combine(target,"runtime","php.exe")))return;
            }
            var check=await StartupCheck.Probe(target);
            if(check is not null){ShowPrerequisite(check.Title,check.Message,check.Url);return;}
            if(!File.Exists(Path.Combine(target,".env"))){ConfigureDatabase();status.Text="Hoàn tất thông tin quản trị trong Cấu hình kết nối trước khi khởi động.";return;}
            await StartUat();
            }catch(Exception ex){status.Text="Chưa khởi động được. Xem nhật ký để biết chi tiết.";log.AppendText(ex.Message+Environment.NewLine);}
            finally{start.Enabled=true;}
        };
        stop.Click+=(_,_)=>StopUat();
        FormClosing+=(_,_)=>StopUat();
        prepare.Click += async (_,_) => await Prepare();
        install.Click += async (_,_) => await Extract();
        Controls.Add(panel);
        Controls.Clear();
        BuildWizard();
        using(var iconStream=Assembly.GetExecutingAssembly().GetManifestResourceStream("VK-CRM.ico"))
            if(iconStream is not null){using var embeddedIcon=new Icon(iconStream);Icon=(Icon)embeddedIcon.Clone();}
        if(openInstalled)Shown+=async(_,_)=>{
            if(!databaseInitialized){status.Text="Chưa có cấu hình hợp lệ. Hoàn tất thiết lập trước khi khởi động.";return;}
            try{var issue=await StartupCheck.Probe(Path.GetFullPath(destination.Text));if(issue is not null)ShowPrerequisite(issue.Title,issue.Message,issue.Url);else await StartUat();}
            catch(Exception ex){status.Text="Chưa khởi động được: "+ex.Message;}
        };
    }

    void ShowPrerequisite(string title,string message,string? url)
    {
        status.Text=title;
        using var dialog=new Form {Text="Kiểm tra khởi động",ClientSize=new Size(560,270),StartPosition=FormStartPosition.CenterParent,Font=Font,BackColor=Color.White,FormBorderStyle=FormBorderStyle.FixedDialog,MaximizeBox=false,MinimizeBox=false};
        var panel=new FlowLayoutPanel{Dock=DockStyle.Fill,Padding=new Padding(24),FlowDirection=FlowDirection.TopDown,WrapContents=false};
        panel.Controls.Add(new Label{Text=title,Font=new Font(Font,FontStyle.Bold),AutoSize=true,MaximumSize=new Size(510,0)});
        panel.Controls.Add(new Label{Text=message,AutoSize=true,MaximumSize=new Size(510,0),Margin=new Padding(0,14,0,18)});
        var row=new FlowLayoutPanel{Width=510,Height=45};
        if(url is not null){var download=new Button{Text="Tải từ Microsoft",AutoSize=true};download.Click+=(_,_)=>{try{Process.Start(new ProcessStartInfo(url){UseShellExecute=true});}catch(Exception ex){MessageBox.Show(dialog,"Không mở được trình duyệt. Đường dẫn: "+url+"\n"+ex.Message);}};row.Controls.Add(download);}
        var retry=new Button{Text="Kiểm tra lại",AutoSize=true};retry.Click+=(_,_)=>dialog.DialogResult=DialogResult.Retry;row.Controls.Add(retry);
        var close=new Button{Text="Đóng",AutoSize=true};close.Click+=(_,_)=>dialog.Close();row.Controls.Add(close);panel.Controls.Add(row);dialog.Controls.Add(panel);
        if(dialog.ShowDialog(this)==DialogResult.Retry) BeginInvoke(new Action(()=>{if(step==4)wizardNext.PerformClick();else start.PerformClick();}));
    }

    void ConfigureDatabase()
    {
        using var dialog=new Form { Text="Cấu hình cơ sở dữ liệu",Size=new Size(560,540),StartPosition=FormStartPosition.CenterParent,Font=Font,BackColor=Color.White };
        var configuring=false;
        dialog.FormClosing+=(_,e)=>{if(configuring)e.Cancel=true;};
        var fields=new FlowLayoutPanel { Dock=DockStyle.Fill,Padding=new Padding(24),FlowDirection=FlowDirection.TopDown,WrapContents=false,AutoScroll=true };
        void Add(string label,Control field){fields.Controls.Add(new Label{Text=label,AutoSize=true,Margin=new Padding(0,10,0,4)});field.Width=470;fields.Controls.Add(field);}
        if(databaseType.SelectedIndex==0||sqlSetupConsent.Checked||sqlTrialEnabled){
            Add("Doanh nghiệp",company);Add("Email Chủ tịch",email);Add("Mật khẩu Chủ tịch (tối thiểu 12 ký tự)",password);
            Add("Email Admin thường",operatorEmail);Add("Mật khẩu Admin thường (tối thiểu 12 ký tự)",operatorPassword);
            var create=new Button{Text="Hoàn tất thiết lập",AutoSize=true,Margin=new Padding(0,16,0,0)};
            create.Click+=async(_,_)=>{
                if(configuring)return;
                configuring=true;create.Enabled=false;
                try{
                    var target=Path.GetFullPath(destination.Text);
                    if(!File.Exists(Path.Combine(target,"runtime","php.exe"))){status.Text="Đang chuẩn bị ứng dụng lần đầu…";await Task.Yield();await Extract();}
                    if(File.Exists(Path.Combine(target,"runtime","php.exe"))){if(databaseType.SelectedIndex==1)await InitializeSqlTrial();else await InitializeUat();}
                    MessageBox.Show(status.Text,"Kết quả",MessageBoxButtons.OK,MessageBoxIcon.Information);
                }finally{configuring=false;create.Enabled=true;}
            };fields.Controls.Add(create);
            fields.Controls.Add(new Label{Text="Chỉ tạo mới. Không ghi đè database đã có.",AutoSize=true});
        }else{
            Add("Máy chủ / Instance",new TextBox{PlaceholderText=@"Ví dụ: SERVER\SQLEXPRESS"});
            Add("Database CRM",new TextBox{PlaceholderText="Ví dụ: VKCRM_UAT"});
            var auth=new ComboBox{DropDownStyle=ComboBoxStyle.DropDownList};auth.Items.AddRange(new object[]{"Windows Authentication","SQL Server Authentication"});auth.SelectedIndex=0;Add("Phương thức đăng nhập",auth);
            var login=new TextBox{Enabled=false};var secret=new TextBox{UseSystemPasswordChar=true,Enabled=false};Add("Tên đăng nhập",login);Add("Mật khẩu",secret);auth.SelectedIndexChanged+=(_,_)=>login.Enabled=secret.Enabled=auth.SelectedIndex==1;
            fields.Controls.Add(new Label{Text="Bản này chưa hỗ trợ lưu/kết nối SQL Server.\nKhông ghi dữ liệu hoặc tự đổi database của khách.",AutoSize=true,ForeColor=Color.FromArgb(180,83,9),Margin=new Padding(0,16,0,0)});
        }
        dialog.Controls.Add(fields);dialog.ShowDialog(this);
        // These fields belong to the manager; avoid disposal with the temporary dialog.
        fields.Controls.Remove(company);fields.Controls.Remove(email);fields.Controls.Remove(password);
        fields.Controls.Remove(operatorEmail);fields.Controls.Remove(operatorPassword);
    }
    bool extractionSucceeded;
    async Task Extract()
    {
        extractionSucceeded=false;
        install.Enabled = false;
        try
        {
            var target = Path.GetFullPath(destination.Text.Trim());
            if (File.Exists(target) || (Directory.Exists(target) && Directory.EnumerateFileSystemEntries(target).Any())) throw new Exception("Thư mục phải mới hoặc trống. Không ghi đè dữ liệu hiện có.");
            for(var parent = new DirectoryInfo(target); parent != null; parent=parent.Parent)
                if(parent.Exists && parent.Attributes.HasFlag(FileAttributes.ReparsePoint)) throw new Exception("Không dùng thư mục liên kết.");
            using(var runtimeStream=Assembly.GetExecutingAssembly().GetManifestResourceStream("portable.zip") ?? throw new Exception("Thiếu runtime"))
            using(var hashStream=Assembly.GetExecutingAssembly().GetManifestResourceStream("portable.sha256") ?? throw new Exception("Thiếu checksum"))
            using(var reader=new StreamReader(hashStream)) {
                var expected=reader.ReadToEnd().Trim();
                if(!Convert.ToHexString(SHA256.HashData(runtimeStream)).Equals(expected,StringComparison.OrdinalIgnoreCase))throw new Exception("Gói runtime sai checksum. Không giải nén.");
            }
            using var stream=Assembly.GetExecutingAssembly().GetManifestResourceStream("crm.zip") ?? throw new Exception("Thiếu gói CRM.");
            using var archive=new ZipArchive(stream,ZipArchiveMode.Read);
            var manifestEntry=archive.GetEntry("PACKAGE-MANIFEST.json") ?? throw new Exception("Thiếu manifest.");
            using var manifestStream=manifestEntry.Open();
            using var manifest=JsonDocument.Parse(manifestStream);
            var files=manifest.RootElement.GetProperty("files");
            var prefix=target.TrimEnd(Path.DirectorySeparatorChar)+Path.DirectorySeparatorChar;
            var processed=0;
            foreach(var entry in archive.Entries)
            {
                var path=Path.GetFullPath(Path.Combine(target,entry.FullName.Replace('/',Path.DirectorySeparatorChar)));
                if(!path.StartsWith(prefix,StringComparison.OrdinalIgnoreCase)) throw new Exception("Đường dẫn gói không an toàn.");
                if(entry.FullName.EndsWith('/')) continue;
                if(entry.FullName=="PACKAGE-MANIFEST.json") continue;
                if(!files.TryGetProperty(entry.FullName,out var expected)) throw new Exception("File ngoài manifest.");
                using var content=entry.Open();
                var hash=Convert.ToHexString(SHA256.HashData(content));
                if(!hash.Equals(expected.GetString(),StringComparison.OrdinalIgnoreCase)) throw new Exception("Gói sai checksum.");
                if(++processed%30==0)await Task.Delay(1);
            }
            Directory.CreateDirectory(target);
            foreach(var entry in archive.Entries)
            {
                var path=Path.GetFullPath(Path.Combine(target,entry.FullName.Replace('/',Path.DirectorySeparatorChar)));
                if(entry.FullName.EndsWith('/')) { Directory.CreateDirectory(path); continue; }
                Directory.CreateDirectory(Path.GetDirectoryName(path)!);
                using var input=entry.Open(); using var output=new FileStream(path,FileMode.CreateNew,FileAccess.Write); input.CopyTo(output);
                if(++processed%30==0)await Task.Delay(1);
            }
            status.Text="Đã kiểm tra gói ứng dụng. Đang chuẩn bị các thành phần cần thiết…";
            using(var portable=Assembly.GetExecutingAssembly().GetManifestResourceStream("portable.zip") ?? throw new Exception("Thiếu runtime"))
            using(var bundle=new ZipArchive(portable,ZipArchiveMode.Read))
            foreach(var entry in bundle.Entries) {
                if(entry.FullName=="initialize-uat.php")continue;
                var path=Path.GetFullPath(Path.Combine(target,entry.FullName));
                if(!path.StartsWith(prefix,StringComparison.OrdinalIgnoreCase))throw new Exception("Unsafe runtime path");
                Directory.CreateDirectory(Path.GetDirectoryName(path)!);
                using var input=entry.Open();using var output=new FileStream(path,FileMode.CreateNew);input.CopyTo(output);
                if(++processed%30==0)await Task.Delay(1);
            }
            using(var helper=Assembly.GetExecutingAssembly().GetManifestResourceStream("initialize-uat.php") ?? throw new Exception("Thiếu helper"))
            using(var helperFile=new FileStream(Path.Combine(target,"initialize-uat.php"),FileMode.CreateNew))helper.CopyTo(helperFile);
            status.Text="Đã chuẩn bị ứng dụng. Nhập thông tin doanh nghiệp và tài khoản quản trị để tiếp tục.";
            // Do not report shell launch success as proof that Explorer opened the folder.
            log.AppendText("Đường dẫn giải nén: "+target+Environment.NewLine);
            extractionSucceeded=true;
        }
        catch(Exception ex) { status.Text="Chưa hoàn tất: "+ex.Message+"\nNếu có thư mục giải nén dở, kiểm tra trước khi thử lại vào thư mục trống khác."; }
        finally { install.Enabled=true; }
    }

    async Task InitializeUat() {
        initialize.Enabled=false;
        try {
            var target=Path.GetFullPath(destination.Text.Trim());
            RequireRuntime(target);
            SoftwareUpdate.EnsureReadyToStart(target);
            if(password.Text.Length<12 || !email.Text.Contains('@') || string.IsNullOrWhiteSpace(company.Text))throw new Exception("Nhập doanh nghiệp, email và mật khẩu ít nhất 12 ký tự.");
            if(operatorPassword.Text.Length<12 || !operatorEmail.Text.Contains('@') || string.Equals(operatorEmail.Text.Trim(),email.Text.Trim(),StringComparison.OrdinalIgnoreCase))throw new Exception("Nhập email riêng và mật khẩu ít nhất 12 ký tự cho Admin thường.");
            if(MessageBox.Show("Tạo cơ sở dữ liệu SQLite mới trên máy tính này? Không ghi đè dữ liệu hiện có.","Xác nhận thiết lập",MessageBoxButtons.YesNo)!=DialogResult.Yes)return;
            var info=new ProcessStartInfo(Path.Combine(target,"runtime","php.exe")){WorkingDirectory=target,UseShellExecute=false,CreateNoWindow=true,RedirectStandardInput=true,RedirectStandardOutput=true,RedirectStandardError=true};
            info.ArgumentList.Add("-c");info.ArgumentList.Add(Path.Combine(target,"runtime","php.ini"));info.ArgumentList.Add("-d");info.ArgumentList.Add("extension_dir="+Path.Combine(target,"runtime","ext"));info.ArgumentList.Add(Path.Combine(target,"initialize-uat.php"));
            ProcessText.UseUtf8(info);
            using var process=Process.Start(info) ?? throw new Exception("Cannot start PHP");
            await process.StandardInput.WriteAsync(JsonSerializer.Serialize(new{company=company.Text,email=email.Text,password=password.Text,operator_email=operatorEmail.Text.Trim(),operator_password=operatorPassword.Text}));process.StandardInput.Close();
            var stdout=process.StandardOutput.ReadToEndAsync();var stderr=process.StandardError.ReadToEndAsync();
            using var timeout=new CancellationTokenSource(TimeSpan.FromMinutes(3));
            try{await process.WaitForExitAsync(timeout.Token);}catch(OperationCanceledException){process.Kill(entireProcessTree:true);throw new Exception("Khởi tạo quá 3 phút. Đã dừng riêng tiến trình thử; giữ thư mục để kiểm tra.");}
            log.AppendText(await stdout+await stderr);if(process.ExitCode!=0)throw new Exception("Khởi tạo thất bại; giữ thư mục để kiểm tra, không tự ghi đè.");
            databaseInitialized=true;password.Clear();operatorPassword.Clear();
            try{SaveInstalledManager(target);status.Text="Thiết lập dữ liệu thành công. Lần sau mở VK CRM.exe trong thư mục cài đặt để khởi động máy chủ.";}
            catch(Exception ex){status.Text="Đã tạo dữ liệu, nhưng chưa tạo được trình mở lại: "+ex.Message;}
        } catch(Exception ex){status.Text=ex.Message;}finally{initialize.Enabled=true;}
    }
    async Task StartUat() {
        start.Enabled=false;
        try {
            if(server is {HasExited:false})return;
            var target=Path.GetFullPath(destination.Text.Trim());
            RequireRuntime(target);
            SoftwareUpdate.EnsureReadyToStart(target);
            if(!File.Exists(Path.Combine(target,".env")))throw new Exception("Vui lòng hoàn tất thiết lập cơ sở dữ liệu trước khi khởi động.");
            var listener=new System.Net.Sockets.TcpListener(System.Net.IPAddress.Loopback,8099);
            try{listener.Start();}
            catch(System.Net.Sockets.SocketException){throw new Exception("Cổng 8099 đang được ứng dụng khác sử dụng. Đóng bản CRM đang chạy rồi thử lại. Không cần tải hoặc cài thêm thành phần.");}
            finally{listener.Stop();}
            var info=new ProcessStartInfo(Path.Combine(target,"runtime","php.exe")){WorkingDirectory=Path.Combine(target,"public"),UseShellExecute=false,CreateNoWindow=true};
            foreach(var arg in new[]{"-c",Path.Combine(target,"runtime","php.ini"),"-d","extension_dir="+Path.Combine(target,"runtime","ext"),"-S","127.0.0.1:8099","-t",Path.Combine(target,"public"),Path.Combine(target,"vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php")})info.ArgumentList.Add(arg);
            server=Process.Start(info) ?? throw new Exception("PHP không khởi động được.");
            using var client=new HttpClient { Timeout=TimeSpan.FromSeconds(2) };
            var ready=false;
            for(var attempt=0;attempt<20;attempt++) {
                if(server.HasExited)throw new Exception("PHP dừng ngay sau khi khởi động. Kiểm tra runtime/Visual C++ và log ứng dụng.");
                try{using var response=await client.GetAsync("http://127.0.0.1:8099/up");if(response.IsSuccessStatusCode){ready=true;break;}}catch(HttpRequestException){}catch(TaskCanceledException){}
                await Task.Delay(300);
            }
            if(!ready){StopUat();throw new Exception("Server không vượt kiểm tra sức khỏe. Chưa mở trình duyệt.");}
            status.Text="Máy chủ đang hoạt động tại http://127.0.0.1:8099. Đóng trình quản lý sẽ dừng máy chủ.";
            Process.Start(new ProcessStartInfo("http://127.0.0.1:8099"){UseShellExecute=true});
        }catch(Exception ex){status.Text="Không mở được: "+ex.Message;}
        finally {start.Enabled=true;}
    }
    void StopUat(){if(server is {HasExited:false})server.Kill(entireProcessTree:true);server?.Dispose();server=null;}

    static void RequireRuntime(string target)
    {
        foreach(var file in new[]{"runtime/php.exe","runtime/php.ini","vendor/autoload.php","artisan","initialize-uat.php"})
            if(!File.Exists(Path.Combine(target,file)))throw new Exception("Thư mục này chưa có đủ bản portable (thiếu "+file+"). Có thể bạn đang chọn thư mục bản giải nén cũ. Hãy chọn thư mục MỚI trống, bấm Giải nén bản thử và chờ thành công trước khi Tạo SQLite UAT. Không cần chọn PHP/Composer và không giải nén đè dữ liệu cũ.");
    }

    static void AddFilePicker(FlowLayoutPanel panel,TextBox field,string filter,string label)
    {
        panel.Controls.Add(field);
        var button=new Button { Text=label,AutoSize=true };
        button.Click+=(_,_)=>{using var dialog=new OpenFileDialog { Filter=filter,CheckFileExists=true };if(dialog.ShowDialog()==DialogResult.OK)field.Text=dialog.FileName;};
        panel.Controls.Add(button);
    }

    async Task Run(string executable,string directory,params string[] arguments)
    {
        var info=new ProcessStartInfo(executable) { WorkingDirectory=directory,UseShellExecute=false,CreateNoWindow=true,RedirectStandardOutput=true,RedirectStandardError=true };
        foreach(var argument in arguments)info.ArgumentList.Add(argument);
        ProcessText.UseUtf8(info);
        using var process=Process.Start(info) ?? throw new Exception("Không chạy được công cụ.");
        var output=process.StandardOutput.ReadToEndAsync();
        var error=process.StandardError.ReadToEndAsync();
        using var timeout=new CancellationTokenSource(TimeSpan.FromMinutes(10));
        try { await process.WaitForExitAsync(timeout.Token); }
        catch(OperationCanceledException) { process.Kill(entireProcessTree:true);throw new Exception("Quá thời gian 10 phút; đã dừng riêng công cụ chuẩn bị. Kiểm tra mạng rồi thử lại."); }
        log.AppendText(await output+await error+Environment.NewLine);
        if(process.ExitCode!=0)throw new Exception("Công cụ thất bại, mã "+process.ExitCode+". Chưa cấu hình hoặc khởi động CRM.");
    }

    async Task Prepare()
    {
        prepare.Enabled=install.Enabled=false;
        try
        {
            var target=Path.GetFullPath(destination.Text.Trim());
            if(!File.Exists(Path.Combine(target,"PACKAGE-MANIFEST.json")) || !File.Exists(Path.Combine(target,"artisan")))throw new Exception("Chọn đúng thư mục UAT đã giải nén.");
            if(File.Exists(Path.Combine(target,".env")))throw new Exception("Đã có .env: từ chối sửa môi trường đang cấu hình. Dùng thư mục thử mới.");
            if(!File.Exists(php.Text) || !File.Exists(composer.Text))throw new Exception("Chọn php.exe và composer.phar có sẵn, từ nguồn tin cậy.");
            if(MessageBox.Show("Sẽ chạy PHP/Composer đã chọn để tải thư viện từ composer.lock. Cần Internet. Không tạo database hay sửa .env. Tiếp tục?","Xác nhận chuẩn bị",MessageBoxButtons.YesNo,MessageBoxIcon.Question)!=DialogResult.Yes)return;
            await Run(php.Text,target,"-v");
            await Run(php.Text,target,composer.Text,"validate","--no-check-publish","--no-interaction");
            await Run(php.Text,target,composer.Text,"install","--no-dev","--prefer-dist","--optimize-autoloader","--no-interaction","--no-scripts","--no-plugins");
            await Run(php.Text,target,composer.Text,"check-platform-reqs","--no-dev");
            await Run(php.Text,target,"artisan","package:discover","--ansi");
            status.Text="Đã cài thư viện và kiểm tra PHP. CHƯA cấu hình database, tạo Admin, đăng ký dịch vụ hoặc khởi chạy CRM. Đọc hướng dẫn UAT trước khi tiếp tục.";
        }
        catch(Exception ex){status.Text="Chuẩn bị chưa hoàn tất: "+ex.Message;}
        finally {prepare.Enabled=install.Enabled=true;}
    }
}
