using System.Diagnostics;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Text;

internal static class LanAccess
{
    internal static string Addresses() => string.Join(" · ", NetworkInterface.GetAllNetworkInterfaces()
        .Where(n => n.OperationalStatus == OperationalStatus.Up && n.NetworkInterfaceType != NetworkInterfaceType.Loopback)
        .SelectMany(n => n.GetIPProperties().UnicastAddresses)
        .Where(a => a.Address.AddressFamily == AddressFamily.InterNetwork && !a.Address.ToString().StartsWith("169.254."))
        .Select(a => "http://" + a.Address + ":8099/").Distinct());

    internal static async Task<bool> EnsureFirewall(string target)
    {
        // Limit the exception to this installed PHP executable and the local subnet.
        var executable=Path.Combine(target,"runtime","php.exe").Replace("'","''");
        var name="VK CRM LAN 8099 "+Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(Encoding.UTF8.GetBytes(Path.GetFullPath(target).ToLowerInvariant())))[..12];
        var check="$ErrorActionPreference='Stop'; $r=Get-NetFirewallRule -Name '"+name+"' -ErrorAction SilentlyContinue; if($r -and $r.Enabled -eq 'True'){exit 0}; exit 2";
        try
        {
            using(var probe=Process.Start(Info(check,false)))
            {
                if(probe is null)return false;
                await probe.WaitForExitAsync();
                if(probe.ExitCode==0)return true;
            }
            var script="$ErrorActionPreference='Stop'; try { Get-NetFirewallRule -Name '"+name+"' -ErrorAction SilentlyContinue | Remove-NetFirewallRule; New-NetFirewallRule -Name '"+name+"' -DisplayName 'VK CRM - May con trong mang noi bo (8099)' -Direction Inbound -Action Allow -Enabled True -Protocol TCP -LocalPort 8099 -RemoteAddress LocalSubnet -Profile Any -Program '"+executable+"' | Out-Null; exit 0 } catch { exit 1 }";
            using var process=Process.Start(Info(script,true));
            if(process is null)return false;
            await process.WaitForExitAsync();
            return process.ExitCode==0;
        }
        catch { return false; }
    }

    static ProcessStartInfo Info(string script,bool elevated)
    {
        var info=new ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System),"WindowsPowerShell","v1.0","powershell.exe"))
        {
            UseShellExecute=elevated, CreateNoWindow=!elevated, WindowStyle=ProcessWindowStyle.Hidden,
            Arguments="-NoProfile -NonInteractive -EncodedCommand "+Convert.ToBase64String(Encoding.Unicode.GetBytes(script))
        };
        if(elevated)info.Verb="runas";
        return info;
    }
}
