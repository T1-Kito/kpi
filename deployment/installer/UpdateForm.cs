using System.Diagnostics;
using System.Reflection;
using System.Net;
using System.Net.Sockets;

internal sealed class UpdateForm : Form
{
    readonly TextBox folder = new() { Width = 490 };
    readonly Label message = new() { AutoSize = true, MaximumSize = new Size(570, 0), Margin = new Padding(0, 20, 0, 16) };
    readonly Button update = new() { Text = "Cập nhật", Width = 120, Height = 38, BackColor = Color.FromArgb(37, 99, 235), ForeColor = Color.White, FlatStyle = FlatStyle.Flat };
    bool busy;
    bool completed;
    string? completedRoot;
    internal UpdateForm(string initial,int? previousManager=null)
    {
        Text = "VK CRM — Cập nhật phần mềm"; Font = new Font("Segoe UI", 10); ClientSize = new Size(650, 375);
        MinimumSize = new Size(666, 414); StartPosition = FormStartPosition.CenterParent; BackColor = Color.White;
        var body = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.TopDown, WrapContents = false, AutoScroll = true, Padding = new Padding(28) };
        body.Controls.Add(new Label { Text = "Cập nhật VK CRM", AutoSize = true, Font = new Font("Segoe UI", 20, FontStyle.Bold), Margin = new Padding(0, 0, 0, 14) });
        body.Controls.Add(new Label { Text = "Giữ nguyên cấu hình kết nối, tài khoản và dữ liệu.", AutoSize = true, Margin = new Padding(0, 0, 0, 22) });
        body.Controls.Add(new Label { Text = "Thư mục VK CRM đang dùng", AutoSize = true });
        var row = new FlowLayoutPanel { Width = 590, Height = 42 }; folder.Text = initial;
        row.Controls.Add(folder);
        var browse = new Button { Text = "Chọn…", Width = 80, Height = 30 };
        browse.Click += (_, _) => { using var picker = new FolderBrowserDialog { Description = "Chọn thư mục VK CRM đã cài", AutoUpgradeEnabled = false, ShowNewFolderButton = false }; if (picker.ShowDialog(this) == DialogResult.OK) folder.Text = picker.SelectedPath; };
        row.Controls.Add(browse); body.Controls.Add(row);
        message.Text = "Đóng máy chủ CRM trước khi cập nhật. Không cần nhập lại thông tin SQL.";
        body.Controls.Add(message); body.Controls.Add(update); Controls.Add(body);
        update.Click += async (_, _) =>
        {
            if (busy) return;
            if (completed) {
                try{var launch=new ProcessStartInfo(Path.Combine(completedRoot!,"VK CRM.exe")){UseShellExecute=true};launch.ArgumentList.Add("--open");Process.Start(launch);Close();}
                catch(Exception ex){MessageBox.Show(this,"Chưa mở được trình quản lý: "+ex.Message);}
                return;
            }
            if (MessageBox.Show(this, "Cập nhật phần mềm trong thư mục đã chọn? Các máy khách cần ngừng sử dụng CRM trong thời gian này.", "Cập nhật VK CRM", MessageBoxButtons.YesNo, MessageBoxIcon.Question) != DialogResult.Yes) return;
            busy = true; update.Enabled = false; folder.Enabled = false; browse.Enabled = false;
            try
            {
                if(previousManager is int pid)
                {
                    try
                    {
                        using var previous=Process.GetProcessById(pid);
                        message.Text="Đang chờ trình quản lý cũ đóng…";
                        using var wait=new CancellationTokenSource(TimeSpan.FromSeconds(30));
                        try{await previous.WaitForExitAsync(wait.Token);}catch(OperationCanceledException){throw new IOException("Trình quản lý cũ chưa đóng. Đóng cửa sổ cũ rồi thử lại.");}
                    }
                    catch(ArgumentException){} // Already exited; never kill an unrelated process.
                }
                using var reservation = new UpdatePort();
                using var package = Assembly.GetExecutingAssembly().GetManifestResourceStream("crm.zip") ?? throw new IOException("Thiếu gói phần mềm.");
                message.Text = await SoftwareUpdate.Apply(folder.Text.Trim(), package, Check, text => { message.Text = text; Refresh(); },upgrade:Upgrade,launcherSource:Environment.ProcessPath ?? throw new IOException("Không xác định được bộ cập nhật."));
                completedRoot=Path.GetFullPath(folder.Text.Trim());completed = true; update.Text = "Mở CRM"; update.Enabled = true;
            }
            catch (Exception ex)
            {
                // All database errors have already been mapped to safe messages by Check.
                message.Text = "Chưa hoàn tất cập nhật. " + ex.Message;
                update.Enabled = true;
            }
            finally { busy = false; folder.Enabled = !completed; browse.Enabled = !completed; }
        };
        FormClosing += (_, e) => { if (busy) e.Cancel = true; };
    }
    sealed class UpdatePort : IDisposable
    {
        readonly TcpListener listener = new(IPAddress.Loopback, 8099);
        internal UpdatePort()
        {
            listener.ExclusiveAddressUse = true;
            try { listener.Start(); }
            catch (SocketException) { listener.Stop(); throw new IOException("CRM đang chạy hoặc cổng 8099 đang bận. Đóng máy chủ CRM rồi thử lại."); }
        }
        public void Dispose() => listener.Stop();
    }
    internal static Task Check(string root,string stage)=>CheckDatabase(root,stage,false);
    internal static Task Upgrade(string root,string stage)=>CheckDatabase(root,stage,true);
    internal static async Task CheckDatabase(string root, string stage,bool upgrade)
    {
        var check = Path.Combine(stage, "update-readonly-check.php");
        if (!File.Exists(check))
        {
            using var source = Assembly.GetExecutingAssembly().GetManifestResourceStream("update-readonly-check.php") ?? throw new IOException("Thiếu thành phần kiểm tra.");
            using var output = new FileStream(check, FileMode.CreateNew); source.CopyTo(output);
        }
        var info = new ProcessStartInfo(Path.Combine(root, "runtime", "php.exe")) { WorkingDirectory = root, UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true };
        foreach (var key in info.Environment.Keys.Where(k => new[] { "APP_", "DB_", "SQLSERVER_", "CACHE_", "SESSION_", "QUEUE_", "JWT_" }.Any(prefix => k.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))).ToArray()) info.Environment.Remove(key);
        foreach (var arg in new[] { "-c", Path.Combine(root, "runtime", "php.ini"), "-d", "extension_dir=" + Path.Combine(root, "runtime", "ext"), check, root, stage }) info.ArgumentList.Add(arg);
        if(upgrade)info.ArgumentList.Add("--upgrade-reviewed");
        ProcessText.UseUtf8(info);
        using var process = Process.Start(info) ?? throw new IOException("Chưa chạy được kiểm tra ứng dụng.");
        var stdout = process.StandardOutput.ReadToEndAsync(); var stderr = process.StandardError.ReadToEndAsync();
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(upgrade?300:45));
        try { await process.WaitForExitAsync(timeout.Token); }
        catch (OperationCanceledException) { process.Kill(true); await process.WaitForExitAsync(); throw new IOException(upgrade?"Nâng cấp quá thời gian. Giữ nguyên backup và gửi kỹ thuật kiểm tra trạng thái database; không tự xóa dữ liệu.":"Kiểm tra kết nối quá thời gian. Chưa chạy nâng cấp database."); }
        var outputText = await stdout; var error = await stderr;
        if (process.ExitCode == 0 && (outputText == "VK_UPDATE_COMPATIBLE" || (!upgrade && outputText == "VK_UPDATE_ADDITIVE_REQUIRED")) && error.Length == 0) return;
        if(error=="UPGRADE_BACKUP_FAILED")throw new IOException("Không sao lưu/kiểm chứng được database. Chưa chạy nâng cấp cấu trúc. Nhờ quản trị kiểm tra quyền BACKUP/VERIFY và thư mục backup trên máy SQL; không dùng sa hoặc tự cấp sysadmin.");
        if(error=="UPGRADE_SCHEMA_CONFLICT" || error=="UPGRADE_BASELINE")throw new IOException("Cấu trúc database không khớp lịch sử nâng cấp được hỗ trợ. Giữ nguyên dữ liệu để kỹ thuật đối chiếu.");
        if (error == "MIGRATION_REQUIRED") throw new IOException("Database thiếu bước nâng cấp ngoài danh sách được hỗ trợ của bản này. Cần kỹ thuật đối chiếu phiên bản cũ; chưa chạy nâng cấp cấu trúc.");
        throw new IOException("Chưa xác nhận được kết nối hoặc cấu trúc database. Giữ nguyên bản cài và liên hệ kỹ thuật. Mã: UPDATE_CHECK.");
    }
}
