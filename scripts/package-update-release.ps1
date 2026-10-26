param(
    [Parameter(Mandatory=$true)][string]$SourcePackage,
    [string]$Version='0.8.10'
)
$ErrorActionPreference='Stop'
if($Version -notmatch '^\d+\.\d+\.\d+$'){throw 'Invalid release version'}
$repositoryRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$sourcePath=(Resolve-Path -LiteralPath $SourcePackage).Path
$runtimePath=Join-Path $repositoryRoot "storage\app\private\portable-payload-$Version.zip"
$buildRoot=Join-Path $repositoryRoot "outputs\installer-test-$Version-20261026"
$delivery=Join-Path $repositoryRoot "outputs\VK-CRM-$Version-Windows-x64.zip"
if((Test-Path -LiteralPath $buildRoot) -or (Test-Path -LiteralPath $delivery)){throw 'Output exists. Preserve it and choose a new release version/output.'}
Push-Location $repositoryRoot
try {
    & php scripts/verify-update-package.php $sourcePath $runtimePath $Version
    if($LASTEXITCODE -ne 0){throw 'Payload verification failed'}
    & dotnet publish deployment/installer/VkCrmSetup.csproj -c Release -r win-x64 -o $buildRoot "-p:SourcePackagePath=$sourcePath" "-p:PortablePayloadPath=$runtimePath"
    if($LASTEXITCODE -ne 0){throw 'Installer build failed'}
    $setup=Join-Path $buildRoot 'Setup.exe'
    $actualVersion=[Diagnostics.FileVersionInfo]::GetVersionInfo($setup).FileVersion
    if($actualVersion -ne "$Version.0"){throw 'Installer version mismatch'}
    foreach($check in @('--self-test','--verify-package')){
        $process=Start-Process -FilePath $setup -ArgumentList $check -WindowStyle Hidden -Wait -PassThru
        if($process.ExitCode -ne 0){throw "Installer check failed: $check"}
    }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip=[IO.Compression.ZipFile]::Open($delivery,[IO.Compression.ZipArchiveMode]::Create)
    $hashes=@{}
    try {
        $files=@{
            'Setup.exe'=$setup
            'HUONG-DAN-CAP-NHAT.txt'=(Join-Path $repositoryRoot 'deployment\installer\UPDATE-0.8.10.txt')
            'Update-Source.zip'=$sourcePath
        }
        foreach($entry in $files.GetEnumerator()){
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip,$entry.Value,$entry.Key,[IO.Compression.CompressionLevel]::Optimal) | Out-Null
            $hashes[$entry.Key]=(Get-FileHash -LiteralPath $entry.Value -Algorithm SHA256).Hash.ToLowerInvariant()
        }
        $sum=$zip.CreateEntry('SHA256SUMS.txt')
        $writer=[IO.StreamWriter]::new($sum.Open(),[Text.UTF8Encoding]::new($false))
        try {foreach($key in ($hashes.Keys | Sort-Object)){$writer.WriteLine("$($hashes[$key])  $key")}}finally{$writer.Dispose()}
    }finally{$zip.Dispose()}
    $checkZip=[IO.Compression.ZipFile]::OpenRead($delivery)
    try {
        if($checkZip.Entries.Count -ne 4){throw 'Unexpected delivery entry count'}
        foreach($key in $hashes.Keys){
            $stream=$checkZip.GetEntry($key).Open();$algorithm=[Security.Cryptography.SHA256]::Create()
            try{$actual=[Convert]::ToHexString($algorithm.ComputeHash($stream)).ToLowerInvariant()}finally{$stream.Dispose();$algorithm.Dispose()}
            if($actual -ne $hashes[$key]){throw "Delivery checksum mismatch: $key"}
        }
    }finally{$checkZip.Dispose()}
    [pscustomobject]@{Package=$delivery;Version=$actualVersion;Bytes=(Get-Item -LiteralPath $delivery).Length;SHA256=(Get-FileHash -LiteralPath $delivery -Algorithm SHA256).Hash;Status='TEST UPDATE — SQL Server UAT / production certification pending'} | ConvertTo-Json
}finally{Pop-Location}
