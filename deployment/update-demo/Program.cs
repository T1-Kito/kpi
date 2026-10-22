using System.Reflection;

namespace VkCrmUpdateDemo;

static class Program
{
    [STAThread]
    static int Main(string[] args)
    {
        if (args.Length == 2 && args[0] == "--self-test") return DemoTests.Run(args[1]);
        ApplicationConfiguration.Initialize();
        if (args.Length == 2 && args[0] == "--preview-image")
        {
            using var form = new UpdateForm();
            form.ShowInTaskbar = false; form.Opacity = 0;
            form.Show(); form.PerformLayout(); Application.DoEvents();
            using var bitmap = new Bitmap(form.Width, form.Height);
            form.DrawToBitmap(bitmap, new Rectangle(Point.Empty, form.Size));
            bitmap.Save(args[1], System.Drawing.Imaging.ImageFormat.Png);
            return 0;
        }
        Application.Run(new UpdateForm());
        return 0;
    }
}

sealed class UpdateForm : Form
{
    readonly string root = DemoUpdate.CreateFixture();
    readonly Label version = new() { AutoSize = true, Font = new Font("Segoe UI", 18, FontStyle.Bold), ForeColor = Color.FromArgb(30, 64, 110) };
    readonly Label status = new() { Text = "Sẵn sàng cập nhật bản minh họa.", AutoSize = true, MaximumSize = new Size(550, 0), ForeColor = Color.FromArgb(71, 85, 105) };
    readonly ProgressBar progress = new() { Width = 552, Height = 8, Style = ProgressBarStyle.Continuous };
    readonly Button update = new() { Text = "Cập nhật", Width = 140, Height = 40, FlatStyle = FlatStyle.Flat, BackColor = Color.FromArgb(37, 99, 235), ForeColor = Color.White };
    readonly TextBox history = new() { Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Vertical, Width = 552, Height = 105, Visible = false };

    public UpdateForm()
    {
        Text = "VK CRM — Cập nhật phần mềm";
        ClientSize = new Size(616, 510); MinimumSize = new Size(632, 549);
        StartPosition = FormStartPosition.CenterScreen; AutoScaleMode = AutoScaleMode.Dpi;
        Font = new Font("Segoe UI", 10); BackColor = Color.FromArgb(247, 249, 252);
        using (var stream = Assembly.GetExecutingAssembly().GetManifestResourceStream("brand.ico"))
            if (stream is not null) { using var icon = new Icon(stream); Icon = (Icon)icon.Clone(); }
        var layout = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.TopDown, WrapContents = false, AutoScroll = true, Padding = new Padding(30, 24, 30, 20) };
        var logo = new PictureBox { Width = 190, Height = 48, SizeMode = PictureBoxSizeMode.Zoom, Margin = new Padding(0, 0, 0, 16) };
        using (var stream = Assembly.GetExecutingAssembly().GetManifestResourceStream("brand.png"))
            if (stream is not null) { using var image = Image.FromStream(stream); logo.Image = new Bitmap(image); }
        layout.Controls.Add(logo);
        layout.Controls.Add(new Label { Text = "Cập nhật phần mềm", Font = new Font("Segoe UI", 18, FontStyle.Bold), AutoSize = true, ForeColor = Color.FromArgb(15, 23, 42), Margin = new Padding(0, 0, 0, 8) });
        layout.Controls.Add(new Label { Text = "Bản minh họa — không thay đổi CRM đang dùng.", AutoSize = true, ForeColor = Color.FromArgb(100, 116, 139), Margin = new Padding(0, 0, 0, 20) });
        var card = new FlowLayoutPanel { Width = 552, Height = 145, Padding = new Padding(20, 14, 20, 10), BackColor = Color.White, FlowDirection = FlowDirection.TopDown, WrapContents = false, Margin = new Padding(0, 0, 0, 18) };
        version.Text = "1.0   →   1.1"; card.Controls.Add(version);
        foreach (var text in new[] { "Kết nối SQL: giữ nguyên", "Khách hàng và tài khoản: giữ nguyên", "Ảnh và tài liệu: giữ nguyên" })
            card.Controls.Add(new Label { Text = text, AutoSize = true, ForeColor = Color.FromArgb(51, 65, 85), Margin = new Padding(0, 4, 0, 0) });
        layout.Controls.Add(card); layout.Controls.Add(status);
        progress.Margin = new Padding(0, 14, 0, 12); layout.Controls.Add(progress);
        var actions = new FlowLayoutPanel { Width = 552, Height = 48, FlowDirection = FlowDirection.RightToLeft, Margin = new Padding(0, 0, 0, 4) };
        var close = new Button { Text = "Đóng", Width = 85, Height = 40, FlatStyle = FlatStyle.Flat, BackColor = Color.White };
        close.Click += (_, _) => Close();
        update.FlatAppearance.BorderSize = 0; actions.Controls.Add(update); actions.Controls.Add(close); layout.Controls.Add(actions);
        var details = new LinkLabel { Text = "Xem kiểm chứng", AutoSize = true, Margin = new Padding(0, 4, 0, 8) };
        details.LinkClicked += (_, _) => { history.Visible = !history.Visible; if (history.Visible) ClientSize = new Size(616, 620); };
        layout.Controls.Add(details); layout.Controls.Add(history); Controls.Add(layout);
        history.Text = "Chỉ dùng dữ liệu mẫu, không kết nối SQL thật." + Environment.NewLine + "Thư mục minh họa: " + root + Environment.NewLine;
        update.Click += async (_, _) => {
            update.Enabled = false; close.Enabled = false;
            var reporter = new Progress<(int Percent, string Text)>(item => { progress.Value = item.Percent; status.Text = item.Text; history.AppendText(item.Text + Environment.NewLine); });
            try
            {
                await Task.Run(() => DemoUpdate.Apply(root, DemoUpdate.Package(), reporter));
                version.Text = "Đã cập nhật lên 1.1";
                status.Text = "Hoàn tất. Không cần nhập lại kết nối SQL.";
                status.ForeColor = Color.FromArgb(21, 128, 61); update.Text = "Đã cập nhật";
                history.AppendText("Đã so sánh checksum: cấu hình, khách hàng và ảnh mẫu không đổi." + Environment.NewLine);
            }
            catch (Exception ex)
            {
                status.Text = "Chưa cập nhật được. Xem kiểm chứng để biết chi tiết.";
                history.AppendText(ex.Message + Environment.NewLine); update.Enabled = true;
            }
            finally { close.Enabled = true; }
        };
        FormClosing += (_, args) => { if (!close.Enabled) args.Cancel = true; };
    }
}
