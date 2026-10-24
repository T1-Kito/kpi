param()
$ErrorActionPreference='Stop'
$releaseRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\outputs\VK-CRM-0.8.9-Windows-x64'))
if(Test-Path -LiteralPath $releaseRoot){throw 'Output exists. Preserve it and choose a new release version.'}
$repositoryRoot=Split-Path -Parent $PSScriptRoot
$source=Join-Path $repositoryRoot 'storage\app\private\installer-release-candidate\Setup.exe'
$newBuild=Join-Path $repositoryRoot 'outputs\installer-test-0.8.9-20261023\Setup.exe'
if(Test-Path -LiteralPath $newBuild -PathType Leaf){$source=$newBuild}
if(!(Test-Path -LiteralPath $source -PathType Leaf)){throw 'Build Setup.exe first.'}
$check=Start-Process -FilePath $source -ArgumentList '--self-test' -WindowStyle Hidden -Wait -PassThru
if($check.ExitCode -ne 0){throw 'Installer UI self-test failed. No package produced.'}
$version=Start-Process -FilePath $source -ArgumentList '--version' -WindowStyle Hidden -Wait -PassThru
if($version.ExitCode -ne 0){throw 'Cannot read installer release version.'}
New-Item -ItemType Directory -Path $releaseRoot | Out-Null
Copy-Item -LiteralPath $source -Destination (Join-Path $releaseRoot 'Setup.exe')
Copy-Item -LiteralPath (Join-Path $repositoryRoot 'deployment\installer\DELIVERY-STATUS.txt') -Destination (Join-Path $releaseRoot 'DOC-TRUOC.txt')
$originalHash=(Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash
$copiedHash=(Get-FileHash -LiteralPath (Join-Path $releaseRoot 'Setup.exe') -Algorithm SHA256).Hash
if($originalHash -ne $copiedHash){throw 'Package copy checksum mismatch.'}
[pscustomobject]@{Directory=$releaseRoot;SHA256=$copiedHash;Status='TEST ONLY — not production approved'} | ConvertTo-Json
