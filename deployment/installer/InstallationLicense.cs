using Microsoft.Win32;
using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

internal static class InstallationLicense
{
    internal static string MachineCode()
    {
        using var key=RegistryKey.OpenBaseKey(RegistryHive.LocalMachine,RegistryView.Registry64).OpenSubKey(@"SOFTWARE\Microsoft\Cryptography");
        var guid=key?.GetValue("MachineGuid") as string;
        if(string.IsNullOrWhiteSpace(guid))throw new InvalidOperationException("Không lấy được mã máy chủ Windows.");
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes("VK-CRM|"+guid.Trim().ToUpperInvariant())));
    }

    internal static void Validate(string json,string publicKey,string machine)
    {
        using var envelope=JsonDocument.Parse(json);
        var payload=Convert.FromBase64String(envelope.RootElement.GetProperty("payload").GetString()!);
        var signature=Convert.FromBase64String(envelope.RootElement.GetProperty("signature").GetString()!);
        using var rsa=RSA.Create();rsa.ImportFromPem(publicKey);
        if(!rsa.VerifyData(payload,signature,HashAlgorithmName.SHA256,RSASignaturePadding.Pkcs1))throw new InvalidOperationException("Chữ ký bản quyền không hợp lệ.");
        using var data=JsonDocument.Parse(payload);var value=data.RootElement;
        if(value.GetProperty("product").GetString()!="VK-CRM" || value.GetProperty("version").GetInt32()!=1)throw new InvalidOperationException("File không phải bản quyền VK CRM được hỗ trợ.");
        if(value.GetProperty("machine").GetString()!=machine)throw new InvalidOperationException("Bản quyền này dành cho máy chủ khác. Liên hệ nhà cung cấp để chuyển máy.");
        if(string.IsNullOrWhiteSpace(value.GetProperty("company").GetString()))throw new InvalidOperationException("Bản quyền thiếu tên doanh nghiệp.");
        if(value.TryGetProperty("expires",out var expires) && expires.ValueKind!=JsonValueKind.Null && DateTimeOffset.Parse(expires.GetString()!,System.Globalization.CultureInfo.InvariantCulture)<=DateTimeOffset.UtcNow)throw new InvalidOperationException("Bản quyền đã hết hạn. Liên hệ nhà cung cấp.");
    }

    internal static bool EnsureActivated(IWin32Window owner,string target)
    {
        using var stream=Assembly.GetExecutingAssembly().GetManifestResourceStream("license-public.pem") ?? throw new InvalidOperationException("Bộ cài thiếu khóa kiểm tra bản quyền.");
        using var reader=new StreamReader(stream);var publicKey=reader.ReadToEnd();var machine=MachineCode();
        var path=Path.Combine(target,"license.vkcrm");
        string problem="Chưa kích hoạt bản quyền cho máy chủ này.";
        try{if(File.Exists(path)){Validate(File.ReadAllText(path),publicKey,machine);return true;}}catch(Exception ex){problem=ex.Message;}
        using var dialog=new Form{Text="Kích hoạt bản quyền VK CRM",Size=new System.Drawing.Size(620,300),StartPosition=FormStartPosition.CenterParent,Font=new System.Drawing.Font("Segoe UI",10)};
        var panel=new FlowLayoutPanel{Dock=DockStyle.Fill,Padding=new Padding(20),FlowDirection=FlowDirection.TopDown,WrapContents=false};dialog.Controls.Add(panel);
        var message=new Label{Text=problem,AutoSize=true,MaximumSize=new System.Drawing.Size(560,0)};
        panel.Controls.Add(message);
        panel.Controls.Add(new Label{Text="Gửi mã máy dưới đây cho nhà cung cấp để nhận file bản quyền.",AutoSize=true});
        panel.Controls.Add(new TextBox{Text=machine,ReadOnly=true,Width=555});
        var import=new Button{Text="Chọn file bản quyền…",AutoSize=true};panel.Controls.Add(import);
        import.Click+=(_,_)=>{
            using var picker=new OpenFileDialog{Filter="Bản quyền VK CRM (*.vkcrm)|*.vkcrm",CheckFileExists=true};
            if(picker.ShowDialog(dialog)!=DialogResult.OK)return;
            try{
                if(new FileInfo(picker.FileName).Length>32768)throw new InvalidOperationException("File bản quyền không hợp lệ.");
                var json=File.ReadAllText(picker.FileName);Validate(json,publicKey,machine);
                var temporary=path+"."+Guid.NewGuid().ToString("N")+".tmp";
                File.WriteAllText(temporary,json);File.Move(temporary,path,true);dialog.DialogResult=DialogResult.OK;dialog.Close();
            }catch(Exception ex){message.Text="Không kích hoạt được: "+ex.Message;}
        };
        return dialog.ShowDialog(owner)==DialogResult.OK;
    }

    internal static void SelfTest()
    {
        using var rsa=RSA.Create(2048);var key=rsa.ExportSubjectPublicKeyInfoPem();
        string Envelope(string machine,string? expires=null){var payload=JsonSerializer.SerializeToUtf8Bytes(new{product="VK-CRM",version=1,company="Test",machine,expires});return JsonSerializer.Serialize(new{payload=Convert.ToBase64String(payload),signature=Convert.ToBase64String(rsa.SignData(payload,HashAlgorithmName.SHA256,RSASignaturePadding.Pkcs1))});}
        Validate(Envelope("A"),key,"A");
        void Reject(Action action){try{action();}catch{return;}throw new Exception("Invalid license accepted");}
        Reject(()=>Validate(Envelope("A"),key,"B"));
        Reject(()=>Validate(Envelope("A","2020-01-01T00:00:00Z"),key,"A"));
        using var other=RSA.Create(2048);Reject(()=>Validate(Envelope("A"),other.ExportSubjectPublicKeyInfoPem(),"A"));
        Reject(()=>Validate("{}",key,"A"));
    }
}
