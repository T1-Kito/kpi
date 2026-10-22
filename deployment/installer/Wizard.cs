using System.Net;
using System.Net.Sockets;

sealed partial class SetupForm
{
    int step;
    bool wizardBusy;
    readonly PictureBox installerLogo=new(){Width=180,Height=60,SizeMode=PictureBoxSizeMode.Zoom,BackColor=Color.White,Padding=new Padding(5),Margin=new Padding(0,0,0,12),AccessibleName="VK Software"};
    readonly string[] stepNames={"Chào mừng","Thư mục","Cơ sở dữ liệu","Cài đặt","Hoàn tất"};
    readonly FlowLayoutPanel wizardBody=new(){Dock=DockStyle.Fill,FlowDirection=FlowDirection.TopDown,WrapContents=false,AutoScroll=true,Padding=new Padding(24),BackColor=Color.FromArgb(235,242,255)};
    readonly FlowLayoutPanel wizardSteps=new(){Dock=DockStyle.Fill,FlowDirection=FlowDirection.TopDown,WrapContents=false,Padding=new Padding(20,24,12,0),BackColor=Color.White};
    readonly Label wizardTitle=new(){AutoSize=true,Font=new Font("Segoe UI",18,FontStyle.Bold),ForeColor=Color.FromArgb(15,23,42)};
    readonly Label wizardNote=new(){AutoSize=true,ForeColor=Color.FromArgb(107,117,144),Margin=new Padding(0,12,0,0)};
    readonly Button wizardBack=new(){Text="Quay lại",Width=95,Height=36};
    readonly Button wizardNext=new(){Text="Tiếp tục",Width=130,Height=36,BackColor=Color.FromArgb(37,99,235),ForeColor=Color.White,FlatStyle=FlatStyle.Flat};
    TableLayoutPanel? wizardContentLayout;

    void BuildWizard()
    {
        using(var stream=System.Reflection.Assembly.GetExecutingAssembly().GetManifestResourceStream("VK-Logo.png") ?? throw new InvalidOperationException("Thiếu logo bộ cài."))
        using(var source=Image.FromStream(stream))installerLogo.Image=new Bitmap(source);
        FormClosed+=(_,_)=>{installerLogo.Image?.Dispose();installerLogo.Dispose();};
        Text="VK CRM — Thiết lập hệ thống";ClientSize=new Size(900,620);MinimumSize=new Size(916,659);BackColor=Color.White;
        var layout=new TableLayoutPanel{Dock=DockStyle.Fill,ColumnCount=2,RowCount=1,Margin=Padding.Empty};
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute,220));layout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent,100));
        layout.Controls.Add(wizardSteps,0,0);
        var right=new TableLayoutPanel{Dock=DockStyle.Fill,RowCount=3,ColumnCount=1,Margin=Padding.Empty,BackColor=Color.FromArgb(235,242,255)};
        wizardContentLayout=right;
        right.RowStyles.Add(new RowStyle(SizeType.Absolute,90));right.RowStyles.Add(new RowStyle(SizeType.Percent,100));right.RowStyles.Add(new RowStyle(SizeType.Absolute,68));
        var header=new Panel{Dock=DockStyle.Fill,Padding=new Padding(24,22,0,0),BackColor=Color.FromArgb(235,242,255)};header.Controls.Add(wizardTitle);right.Controls.Add(header,0,0);right.Controls.Add(wizardBody,0,1);
        var footer=new FlowLayoutPanel{Dock=DockStyle.Fill,Padding=new Padding(20,14,0,0),BackColor=Color.FromArgb(222,233,252)};
        wizardNote.Width=130;footer.Controls.Add(wizardNote);
        var cancel=new Button{Text="Đóng",Width=85,Height=36};cancel.Click+=(_,_)=>{if(!wizardBusy)Close();};footer.Controls.Add(cancel);footer.Controls.Add(wizardBack);footer.Controls.Add(wizardNext);right.Controls.Add(footer,0,2);
        layout.Controls.Add(right,1,0);Controls.Add(layout);
        wizardBack.Click+=(_,_)=>{if(step>0&&!wizardBusy){step--;RenderWizard();}};
        wizardNext.Click+=async(_,_)=>{
            if(wizardBusy)return;
            if(step==4){
                if(!databaseInitialized){ConfigureDatabase();RenderWizard();return;}
                wizardNext.Enabled=false;
                try{var issue=await StartupCheck.Probe(Path.GetFullPath(destination.Text.Trim()));if(issue is not null)ShowPrerequisite(issue.Title,issue.Message,issue.Url);else await StartUat();}
                catch(Exception ex){status.Text="Chưa khởi động được: "+ex.Message;}
                finally{wizardNext.Enabled=true;}
                return;
            }
            if(step==0){if(!CheckMachine())return;}
            if(step==1){try{var path=Path.GetFullPath(destination.Text.Trim());if(File.Exists(path)||(Directory.Exists(path)&&Directory.EnumerateFileSystemEntries(path).Any())){MessageBox.Show(this,"Chọn thư mục mới hoặc trống. Không giải nén đè dữ liệu đã có.");return;}}catch(Exception ex){MessageBox.Show(this,ex.Message);return;}}
            if(step==2&&databaseType.SelectedIndex==1){
                if(!sqlSetupConsent.Checked){MessageBox.Show(this,"Vui lòng xác nhận cơ sở dữ liệu được chọn dành cho cài đặt mới. Hệ thống không ghi đè dữ liệu hiện có.","Xác nhận dữ liệu",MessageBoxButtons.OK,MessageBoxIcon.Information);return;}
                if(!StartupCheck.HasOdbc()){ShowPrerequisite("Thiếu ODBC Driver 18","Cài trình kết nối từ Microsoft rồi thử lại.",StartupCheck.OdbcUrl);return;}
                var selectedDatabase=sqlDatabaseName.Text.Trim();
                if(!System.Text.RegularExpressions.Regex.IsMatch(selectedDatabase,@"\A[\p{L}\p{N}_ -]{1,128}\z")||new[]{"master","model","msdb","tempdb"}.Contains(selectedDatabase,StringComparer.OrdinalIgnoreCase)||string.IsNullOrWhiteSpace(sqlServerName.Text)){MessageBox.Show(this,"Nhập máy chủ và tên cơ sở dữ liệu hợp lệ. Không chọn database hệ thống; chỉ sử dụng cơ sở dữ liệu mới hoặc trống.");return;}
                var sqlAction=sqlDatabaseMode.SelectedIndex==1?"Tạo database MỚI và các bảng CRM":"Tạo bảng trong database TRỐNG có sẵn";
                if(MessageBox.Show(this,sqlAction+"\nMáy chủ: "+sqlServerName.Text.Trim()+"\nCơ sở dữ liệu: "+sqlDatabaseName.Text.Trim()+"\nKhông ghi đè dữ liệu hiện có. Dữ liệu còn liên kết được bảo vệ khi xóa.\nBạn muốn bắt đầu thiết lập?","Xác nhận thiết lập",MessageBoxButtons.YesNo,MessageBoxIcon.Warning)!=DialogResult.Yes)return;
            }
            step++;RenderWizard();
            if(step==3)await InstallWizard();
        };
        FormClosing+=(_,e)=>{if(wizardBusy)e.Cancel=true;};
        TryResumeInstalledManager();
        RenderWizard();
    }

    void WizardText(string text,bool heading=false)
    {
        wizardBody.Controls.Add(new Label{Text=text,AutoSize=true,MaximumSize=new Size(570,0),TextAlign=ContentAlignment.TopLeft,ForeColor=heading?Color.FromArgb(15,23,42):Color.FromArgb(71,85,105),Font=new Font("Segoe UI",heading?17:10.5f,heading?FontStyle.Bold:FontStyle.Regular),Margin=new Padding(0,0,0,heading?20:16)});
    }

    void ShowInstalledUpdate()
    {
        using var picker=new OpenFileDialog{Title="Chọn Setup.exe mới đã tải về",Filter="Bộ cài VK CRM (Setup.exe)|Setup.exe",CheckFileExists=true};
        if(picker.ShowDialog(this)!=DialogResult.OK)return;
        var target=Path.GetFullPath(destination.Text.Trim());
        var setup=Path.GetFullPath(picker.FileName);
        var selectedVersion=System.Diagnostics.FileVersionInfo.GetVersionInfo(setup).FileVersion;
        var currentVersion=typeof(SetupForm).Assembly.GetName().Version ?? new Version(0,8,4,0);
        if(!Version.TryParse(selectedVersion,out var updateVersion) || updateVersion<new Version(0,8,4,0) || updateVersion<currentVersion) {MessageBox.Show(this,"Chọn bộ cài VK CRM 0.8.4 trở lên và không thấp hơn bản đang dùng.");return;}
        if(setup.StartsWith(Path.TrimEndingDirectorySeparator(target)+Path.DirectorySeparatorChar,StringComparison.OrdinalIgnoreCase)) {MessageBox.Show(this,"Chọn Setup.exe mới nằm ngoài thư mục CRM đang cài.");return;}
        if(MessageBox.Show(this,"CRM sẽ dừng và cửa sổ quản lý sẽ đóng để mở bộ cập nhật mới. Tiếp tục?","Cập nhật phần mềm",MessageBoxButtons.YesNo,MessageBoxIcon.Question)!=DialogResult.Yes)return;
        var info=new System.Diagnostics.ProcessStartInfo(setup){UseShellExecute=true};info.ArgumentList.Add("--update");info.ArgumentList.Add(target);info.ArgumentList.Add("--wait-for");info.ArgumentList.Add(Environment.ProcessId.ToString());
        System.Diagnostics.Process.Start(info);StopUat();Close();
    }
    bool CheckMachine()
    {
        try{
            if(!Environment.Is64BitOperatingSystem)throw new Exception("Bản cài này cần Windows 64-bit.");
            var root=Path.GetPathRoot(Path.GetFullPath(destination.Text.Trim()))!;
            if(new DriveInfo(root).AvailableFreeSpace<1024L*1024*1024)throw new Exception("Ổ cài đặt cần ít nhất 1 GB dung lượng trống.");
            var listener=new TcpListener(IPAddress.Loopback,8099);try{listener.Start();}finally{listener.Stop();}
            return true;
        }catch(SocketException){MessageBox.Show(this,"Cổng 8099 đang được sử dụng. Đóng bản CRM đang chạy rồi kiểm tra lại.");}
        catch(Exception ex){MessageBox.Show(this,ex.Message,"Kiểm tra hệ thống");}return false;
    }

    void RenderWizard()
    {
        // Remove reusable fields without disposing them or losing entered values.
        wizardBody.Controls.Clear();wizardSteps.Controls.Clear();
        wizardSteps.Controls.Add(installerLogo);
        wizardSteps.Controls.Add(new Label{Text="Quản lý máy chủ",AutoSize=true,ForeColor=Color.FromArgb(100,116,139),Margin=new Padding(0,0,0,28)});
        for(int i=0;i<stepNames.Length;i++)wizardSteps.Controls.Add(new Label{Text=(i<step?"✓":(i+1).ToString())+"    "+stepNames[i],AutoSize=false,Width=185,Height=42,TextAlign=ContentAlignment.MiddleLeft,ForeColor=i==step?Color.FromArgb(37,99,235):Color.FromArgb(100,116,139),Font=new Font(Font,i==step?FontStyle.Bold:FontStyle.Regular)});
        wizardTitle.Text=stepNames[step];wizardNote.Text=$"Bước {step+1} / 5";wizardBack.Enabled=step>0&&step<3;wizardNext.Enabled=step!=3;wizardNext.Text=step==2?"Cài đặt":step==4?"Khởi động":"Tiếp tục";
        wizardTitle.Visible=step!=0;
        if(wizardContentLayout is not null)wizardContentLayout.RowStyles[0].Height=step==0?0:90;
        wizardBody.Padding=new Padding(28,step==0?28:16,24,24);
        if(step==0){WizardText("VK CRM",true);WizardText("Cài mới hoặc cập nhật hệ thống quản lý doanh nghiệp.");WizardText("Windows 64-bit · Dung lượng trống tối thiểu 1 GB\nCổng ứng dụng: 8099");WizardText("Chọn Tiếp tục để cài mới.");var updateExisting=new Button{Text="Cập nhật bản đã cài",Width=230,Height=42,FlatStyle=FlatStyle.Flat};updateExisting.Click+=(_,_)=>{using var form=new UpdateForm(destination.Text);form.ShowDialog(this);};wizardBody.Controls.Add(updateExisting);}
        if(step==1){WizardText("THƯ MỤC CÀI ĐẶT",true);destination.Dock=DockStyle.None;destination.Width=580;wizardBody.Controls.Add(destination);var browse=new Button{Text="Duyệt…",AutoSize=true};browse.Click+=(_,_)=>BrowseDestination(browse);wizardBody.Controls.Add(browse);WizardText("Không sử dụng thư mục website hoặc dữ liệu đang chạy. Không ghi đè dữ liệu có sẵn.");}
        if(step==2)RenderDatabasePage();
        if(step==3){WizardText("Đang chuẩn bị ứng dụng",true);wizardBody.Controls.Add(status);log.Visible=true;log.Width=580;log.Height=200;wizardBody.Controls.Add(log);}
        if(step==4 && databaseInitialized){var updateButton=new Button{Text="Cập nhật phần mềm…",Width=220,Height=40};updateButton.Click+=(_,_)=>{try{ShowInstalledUpdate();}catch(Exception ex){MessageBox.Show(this,"Chưa mở được bộ cập nhật: "+ex.Message);}};wizardBody.Controls.Add(updateButton);}
        if(step==4){wizardTitle.Text=databaseInitialized?"Hoàn tất":"Thiết lập tài khoản quản trị";wizardNext.Text=databaseInitialized?"Khởi động":"Cấu hình";WizardText(databaseInitialized?"✓ Thiết lập dữ liệu thành công":"Ứng dụng đã sẵn sàng để thiết lập dữ liệu",true);WizardText(destination.Text+"\n"+(databaseType.SelectedIndex==1?"SQL Server":"SQLite")+" · Cổng 8099");WizardText(databaseInitialized?"Chọn Khởi động để mở VK CRM. Đóng cửa sổ này sẽ dừng máy chủ ứng dụng.":"Tạo tài khoản quản trị để tiếp tục. Nếu có lỗi, giữ nguyên thư mục và liên hệ người phụ trách triển khai.");var row=new FlowLayoutPanel{Width=580,Height=45};row.Controls.Add(stop);wizardBody.Controls.Add(row);wizardBody.Controls.Add(status);}
    }

    async Task InstallWizard()
    {
        wizardBusy=true;
        try{
            if(!CheckMachine()){step=1;return;}
            status.Text="Đang kiểm tra và giải nén…";await Task.Delay(50);await Extract();
            if(!extractionSucceeded){MessageBox.Show(this,status.Text,"Chưa cài đặt thành công",MessageBoxButtons.OK,MessageBoxIcon.Warning);step=1;return;}
            var target=Path.GetFullPath(destination.Text.Trim());
            if(databaseType.SelectedIndex==1)PrepareInstalledSqlRuntime(target);
            var issue=await StartupCheck.Probe(target);
            if(issue is not null){ShowPrerequisite(issue.Title,issue.Message,issue.Url);step=1;return;}
            status.Text="Đã kiểm tra bộ ứng dụng. Tạo tài khoản quản trị để hoàn tất.";
            ConfigureDatabase();
            step=4;
        }catch(Exception ex){status.Text="Chưa hoàn tất: "+ex.Message;step=1;MessageBox.Show(this,status.Text);}
        finally{wizardBusy=false;RenderWizard();}
    }

    void BrowseDestination(Button browse)
    {
        browse.Enabled=false;
        try
        {
            using var picker=new FolderBrowserDialog
            {
                Description="Chọn thư mục mới hoặc trống",
                UseDescriptionForTitle=true,
                ShowNewFolderButton=true,
                // Start locally; do not restore a previous network or removable location.
                InitialDirectory=Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                AutoUpgradeEnabled=false
            };
            if(picker.ShowDialog(this)==DialogResult.OK)destination.Text=picker.SelectedPath;
        }
        catch(Exception ex)
        {
            MessageBox.Show(this,"Chưa mở được hộp chọn thư mục. Bạn có thể nhập đường dẫn trực tiếp vào ô Thư mục cài đặt.\n\n"+ex.Message,"Chọn thư mục",MessageBoxButtons.OK,MessageBoxIcon.Warning);
        }
        finally{browse.Enabled=true;}
    }
}
