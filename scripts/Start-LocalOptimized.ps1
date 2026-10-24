param([ValidateRange(1024,65535)][int]$Port=8000)
$ErrorActionPreference='Stop'
$projectPath=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$publicPath=Join-Path $projectPath 'public'
$routerPath=Join-Path $projectPath 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
$phpCommand=Get-Command php -ErrorAction Stop
$phpPath=$phpCommand.Source
$opcachePath=Join-Path (Split-Path $phpPath) 'ext/php_opcache.dll'
if(!(Test-Path -LiteralPath $routerPath) -or !(Test-Path -LiteralPath $opcachePath)){throw 'Thiếu router Laravel hoặc OPcache của đúng bộ PHP.'}
if(Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue){throw "Cổng $Port đang được dùng. Dừng đúng máy chủ local trước khi chạy; không tự dừng ứng dụng khác."}
$logPath=Join-Path $projectPath '.tmp'
New-Item -ItemType Directory -Path $logPath -Force | Out-Null
$stamp=Get-Date -Format 'yyyyMMdd-HHmmss-fff'
$arguments=@()
if(!((& $phpPath -m) -contains 'Zend OPcache')){$arguments+=@('-d',('zend_extension="'+$opcachePath+'"'))}
$arguments+=@('-d','opcache.enable_cli=1','-d','opcache.validate_timestamps=1','-d','opcache.revalidate_freq=0','-S',"127.0.0.1:$Port",'-t',('"'+$publicPath+'"'),('"'+$routerPath+'"'))
$process=Start-Process -FilePath $phpPath -ArgumentList $arguments -WorkingDirectory $publicPath -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logPath "local-$stamp.out.log") -RedirectStandardError (Join-Path $logPath "local-$stamp.err.log")
[pscustomobject]@{ProcessId=$process.Id;Url="http://127.0.0.1:$Port";OpcodeCache=$true}
