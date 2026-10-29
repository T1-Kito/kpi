<?php

// Isolated HTTP smoke test against the actual protected archive; no customer DB or .env.
require dirname(__DIR__).'/vendor/autoload.php';
$archive = $argv[1] ?? throw new RuntimeException('Pass protected source ZIP');
$stage = dirname(__DIR__).'/.tmp/license-release-'.bin2hex(random_bytes(5));
mkdir($stage, 0700, true);
$zip = new ZipArchive();
if ($zip->open($archive) !== true) throw new RuntimeException('Unreadable archive');
foreach (json_decode($zip->getFromName('PACKAGE-MANIFEST.json'), true)['files'] as $name=>$hash) {
    if (str_contains($name,'..') || str_starts_with($name,'/')) throw new RuntimeException('Unsafe archive');
}
$zip->extractTo($stage);$zip->close();
if(!is_dir($stage.'/bootstrap/cache'))mkdir($stage.'/bootstrap/cache',0700,true);
spl_autoload_register(function($class) use($stage) {
    if(str_starts_with($class,'App\\')) {
        $path=$stage.'/app/'.str_replace('\\','/',substr($class,4)).'.php';
        if(is_file($path)) require $path;
    }
},true,true);
$app=require $stage.'/bootstrap/app.php';
$app->useEnvironmentPath($stage); // Never load workspace or customer environment.
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$blocked=$kernel->handle(Illuminate\Http\Request::create('/up'));
if($blocked->getStatusCode()!==403)throw new RuntimeException('Unlicensed protected app was not blocked');
$machine=App\Support\InstallationLicense::machineCode();
$issue=new Symfony\Component\Process\Process(['pwsh','-NoProfile','-File',dirname(__DIR__).'/scripts/issue-license.ps1','-Company','Protected release test','-MachineCode',$machine,'-OutputFile',$stage.'/license.vkcrm']);
$issue->setTimeout(30)->mustRun();
$valid=$kernel->handle(Illuminate\Http\Request::create('/up'));
if($valid->getStatusCode()!==200)throw new RuntimeException('Signed license did not unlock protected app');
unlink($stage.'/license.vkcrm');
$blockedAgain=$kernel->handle(Illuminate\Http\Request::create('/up'));
if($blockedAgain->getStatusCode()!==403)throw new RuntimeException('Removed license was not blocked');
echo "PASS actual protected archive: unlicensed HTTP blocked, PowerShell-issued signature accepted, health available with license, removal blocks again.\n";
echo "Isolated test directory (no customer data): $stage\n";
