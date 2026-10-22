param(
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$DriverDirectory = '',
    [switch]$Connect
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
if (!$DriverDirectory) { $DriverDirectory = Join-Path $projectRoot '.tmp/sqlserver-runtime/drivers/Windows' }
$driverPaths = @(
    (Join-Path $DriverDirectory 'php_sqlsrv_82_ts_x64.dll'),
    (Join-Path $DriverDirectory 'php_pdo_sqlsrv_82_ts_x64.dll')
)
if (!(Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw 'PHP executable not found.' }
$runtime = & $PhpPath -r 'echo PHP_MAJOR_VERSION, chr(46), PHP_MINOR_VERSION, chr(124), PHP_INT_SIZE, chr(124), PHP_ZTS;'
if ($runtime -ne '8.2|8|1') { throw 'This test runtime requires PHP 8.2 x64 Thread Safe.' }
foreach ($driverPath in $driverPaths) {
    if (!(Test-Path -LiteralPath $driverPath -PathType Leaf)) { throw "Missing driver: $driverPath" }
    $signature = Get-AuthenticodeSignature -LiteralPath $driverPath
    if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') { throw 'Driver must have a valid Microsoft signature.' }
}
$arguments = @('-d', "extension=$($driverPaths[0])", '-d', "extension=$($driverPaths[1])", (Join-Path $projectRoot 'artisan'), 'crm:sqlserver-check')
if ($Connect) { $arguments += '--connect' }
& $PhpPath @arguments
exit $LASTEXITCODE
