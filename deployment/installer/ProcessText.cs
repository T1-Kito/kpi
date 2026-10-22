using System.Diagnostics;
using System.Text;

internal static class ProcessText
{
    internal static void UseUtf8(ProcessStartInfo info)
    {
        if(info.RedirectStandardInput)info.StandardInputEncoding=new UTF8Encoding(false,true);
        if(info.RedirectStandardOutput)info.StandardOutputEncoding=new UTF8Encoding(false,true);
        if(info.RedirectStandardError)info.StandardErrorEncoding=new UTF8Encoding(false,true);
    }

    internal static void TestRoundTrip(string php)
    {
        const string expected="Kết nối và tiếng Việt đạt. Khách hàng · Nhân sự · Mật khẩu 🔒";
        var info=new ProcessStartInfo(php){UseShellExecute=false,CreateNoWindow=true,RedirectStandardInput=true,RedirectStandardOutput=true,RedirectStandardError=true};
        UseUtf8(info);
        foreach(var arg in new[]{"-n","-r","$s=stream_get_contents(STDIN); echo $s; fwrite(STDERR,$s);"})info.ArgumentList.Add(arg);
        using var process=Process.Start(info)??throw new IOException("Cannot start encoding test");
        var output=process.StandardOutput.ReadToEndAsync();var error=process.StandardError.ReadToEndAsync();
        process.StandardInput.Write(expected);process.StandardInput.Close();
        if(!process.WaitForExit(10000)){process.Kill(true);throw new IOException("Encoding test timeout");}
        if(process.ExitCode!=0||output.GetAwaiter().GetResult()!=expected||error.GetAwaiter().GetResult()!=expected)throw new IOException("UTF-8 subprocess round trip failed");
    }
}
