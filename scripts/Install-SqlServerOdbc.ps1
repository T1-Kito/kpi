param([string]$InstallerPath = '')
$ErrorActionPreference = 'Stop'
if (!$InstallerPath) {
    $InstallerPath = Join-Path (Split-Path -Parent $PSScriptRoot) '.tmp/sqlserver-runtime/msodbcsql18-x64.msi'
}
if (!(Test-Path -LiteralPath $InstallerPath -PathType Leaf)) { throw 'Microsoft ODBC installer not found.' }
$signature = Get-AuthenticodeSignature -LiteralPath $InstallerPath
if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') {
    throw 'Installer must have a valid Microsoft signature.'
}
$administrator = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (!$administrator) { throw 'Open PowerShell as Administrator, then run this script again. No installation attempted.' }
# Interactive installer: the operator reviews licensing and confirms installation.
$process = Start-Process -FilePath 'msiexec.exe' -ArgumentList @('/i',('"' + (Resolve-Path -LiteralPath $InstallerPath).Path + '"'),'/norestart') -Wait -PassThru
if ($process.ExitCode -notin @(0,3010)) { throw "Installer did not succeed. Exit code: $($process.ExitCode)" }
Get-OdbcDriver -Name '*ODBC Driver*SQL Server*' | Select-Object Name,Platform
if ($process.ExitCode -eq 3010) { Write-Warning 'Windows reports a restart is required. No automatic restart was performed.' }
