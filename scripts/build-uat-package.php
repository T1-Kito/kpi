<?php
// Source allowlist; optional authorized single-customer account profile is explicit below.
$root=dirname(__DIR__);
require_once __DIR__.'/php-release-obfuscator.php';
$protected=!in_array('--unprotected-development-package',$argv,true);
$licensePublicKey=$protected?file_get_contents($root.'/deployment/installer/license-public.pem'):'';
if($protected && !openssl_pkey_get_public($licensePublicKey))throw new RuntimeException('Missing valid license public key');
$releaseVersion=$argv[1] ?? '0.8.10';
$includeDefaultAccounts=in_array('--include-authorized-default-accounts',$argv,true);
if($includeDefaultAccounts && !is_file($root.'/deployment/windows/default-accounts.json'))throw new RuntimeException('Authorized account profile is missing.');
if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$releaseVersion))throw new RuntimeException('Invalid release version');
$output=$root.'/storage/app/private/release-packages';
if (!is_dir($output) && !mkdir($output,0700,true)) throw new RuntimeException('Cannot create output directory');
$name='vk-crm-uat-source-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.zip';
$zip=new ZipArchive();
if ($zip->open($output.'/'.$name,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Cannot create package');
$manifest=[];
$add=function(string $path,string $relative) use($zip,&$manifest,$protected,$licensePublicKey):void {
    if (is_link($path)) throw new RuntimeException('Refusing symlink: '.$relative);
    $content=file_get_contents($path);
    if($protected && $relative==='config/installation-license.php')$content='<?php return '.var_export(['required'=>true,'public_key'=>$licensePublicKey],true).';';
    // Migration history must retain exact bytes for safe upgrades from existing installations.
    if($protected && str_ends_with($relative,'.php') && !str_ends_with($relative,'.blade.php') && !str_starts_with($relative,'database/migrations/'))$content=obfuscateReleasePhp($content);
    if (!$zip->addFromString($relative,$content)) throw new RuntimeException('Cannot add '.$relative);
    $manifest[$relative]=hash('sha256',$content);
};
try {
    foreach (['app','config','routes','resources','database/migrations','deployment/windows'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) throw new RuntimeException('Symlink in source');
            if(str_replace('\\','/',substr($file->getPathname(),strlen($root)+1))==='deployment/windows/default-accounts.json' && !$includeDefaultAccounts)continue;
            if ($file->isFile()) $add($file->getPathname(),str_replace('\\','/',substr($file->getPathname(),strlen($root)+1)));
        }
    }
    foreach (['artisan','composer.json','composer.lock','bootstrap/app.php','bootstrap/providers.php','public/index.php','public/.htaccess','public/robots.txt','public/favicon.ico'] as $relative) {
        if (is_file($root.'/'.$relative)) $add($root.'/'.$relative,$relative);
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/assets',FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) throw new RuntimeException('Symlink in assets');
        if ($file->isFile()) $add($file->getPathname(),str_replace('\\','/',substr($file->getPathname(),strlen($root)+1)));
    }
    foreach (['bootstrap/cache','storage/app/private','storage/app/public','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs'] as $directory) $zip->addEmptyDir($directory);
    $zip->addFromString('PACKAGE-MANIFEST.json',json_encode(['type'=>'UAT_SOURCE_NOT_INSTALLER','release_version'=>$releaseVersion,'protection'=>$protected?'php-obfuscation-v1':'none','sqlserver_certified'=>false,'files'=>$manifest],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
} finally {$zip->close();}
$check=new ZipArchive();$check->open($output.'/'.$name);
foreach ($manifest as $relative=>$hash) if (!hash_equals($hash,hash('sha256',$check->getFromName($relative)))) throw new RuntimeException('Package verification failed: '.$relative);
$check->close();
echo $output.'/'.$name.PHP_EOL;
echo 'Verified files: '.count($manifest).PHP_EOL;
echo 'Source-only UAT package; NOT a production installer.'.PHP_EOL;
