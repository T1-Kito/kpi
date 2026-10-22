using System.Threading;

sealed partial class SetupForm
{
    internal void TestWizard()
    {
        static void Assert(bool condition){if(!condition)throw new InvalidOperationException("Wizard self-test failed");}
        Assert(Thread.CurrentThread.GetApartmentState()==ApartmentState.STA);
        Assert(installerLogo.Image is not null);
        step=1;RenderWizard();
        var original=destination.Text;
        destination.Text=Path.Combine(Path.GetTempPath(),"VK CRM UI Test");
        step=2;RenderWizard();
        sqlServerName.Text=@"localhost\MSSQLSERVER01";
        sqlDatabaseName.Text="VKCRM_UI_TEST";
        databaseType.SelectedIndex=1;
        RenderWizard();
        Assert(sqlServerName.Text==@"localhost\MSSQLSERVER01");
        Assert(sqlDatabaseName.Text=="VKCRM_UI_TEST");
        Assert(sqlPassword.UseSystemPasswordChar);
        Assert(sqlDatabaseMode.SelectedIndex==0&&!sqlSetupConsent.Checked&&!sqlResumeEmpty.Checked);
        step=1;RenderWizard();Assert(destination.Text.EndsWith("VK CRM UI Test"));
        step=4;databaseInitialized=false;RenderWizard();
        Assert(wizardTitle.Text!="Hoàn tất"&&wizardNext.Text=="Cấu hình");
        databaseInitialized=true;RenderWizard();Assert(wizardNext.Text=="Khởi động");
        Assert(wizardBody.Controls.OfType<Button>().Any(button=>button.Text=="Cập nhật phần mềm…"));
        destination.Text=original;databaseInitialized=false;databaseType.SelectedIndex=0;step=0;RenderWizard();
        Assert(!wizardTitle.Visible);
        Assert(wizardBody.Controls.OfType<Button>().Any(button=>button.Text=="Cập nhật bản đã cài"));
        using(var updater=new UpdateForm(original))Assert(updater.Text=="VK CRM — Cập nhật phần mềm");
        TestDesktopShortcut();
    }
}
