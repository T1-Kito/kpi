param(
    [ValidateSet('Initialize','Issue')][string]$Action='Issue',
    [string]$KeyDirectory=(Join-Path $HOME '.vk-crm-license'),
    [string]$Company,
    [string]$MachineCode,
    [string]$OutputFile
)
$ErrorActionPreference='Stop'
$privatePath=Join-Path $KeyDirectory 'private.pem'
if($Action -eq 'Initialize'){
    if(Test-Path -LiteralPath $privatePath){throw 'Khóa đã tồn tại. Không tạo lại khóa cho các bản đã phát hành.'}
    New-Item -ItemType Directory -Path $KeyDirectory -Force | Out-Null
    $rsa=[Security.Cryptography.RSA]::Create(3072)
    try{
        [IO.File]::WriteAllText($privatePath,$rsa.ExportPkcs8PrivateKeyPem())
        $publicPath=Join-Path $PSScriptRoot '../deployment/installer/license-public.pem'
        [IO.File]::WriteAllText($publicPath,$rsa.ExportSubjectPublicKeyInfoPem())
    }finally{$rsa.Dispose()}
    Write-Output 'Đã tạo khóa cấp bản quyền riêng và khóa công khai cho bộ cài. Sao lưu private.pem ở nơi riêng; không gửi cho khách.'
    exit
}
if([string]::IsNullOrWhiteSpace($Company) -or $MachineCode -notmatch '^[A-Fa-f0-9]{64}$' -or [string]::IsNullOrWhiteSpace($OutputFile)){throw 'Cần tên công ty, mã máy 64 ký tự và đường dẫn file đầu ra.'}
if(Test-Path -LiteralPath $OutputFile){throw 'File đầu ra đã tồn tại. Hãy chọn tên khác.'}
$rsa=[Security.Cryptography.RSA]::Create()
try{
    $rsa.ImportFromPem([IO.File]::ReadAllText($privatePath))
    $payload=[Text.Encoding]::UTF8.GetBytes((@{product='VK-CRM';version=1;company=$Company.Trim();machine=$MachineCode.ToUpperInvariant();expires=$null;issued=[DateTimeOffset]::UtcNow.ToString('O');id=[guid]::NewGuid().ToString()} | ConvertTo-Json -Compress))
    $signature=$rsa.SignData($payload,[Security.Cryptography.HashAlgorithmName]::SHA256,[Security.Cryptography.RSASignaturePadding]::Pkcs1)
    $json=@{payload=[Convert]::ToBase64String($payload);signature=[Convert]::ToBase64String($signature)} | ConvertTo-Json
    [IO.File]::WriteAllText([IO.Path]::GetFullPath($OutputFile),$json,[Text.UTF8Encoding]::new($false))
    Write-Output 'Đã cấp bản quyền vĩnh viễn. Chỉ gửi file .vkcrm cho khách.'
}finally{$rsa.Dispose()}
