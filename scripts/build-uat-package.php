<?php
// Source-only allowlist. Never bundle customer data, secrets or local runtimes.
$root=dirname(__DIR__);
$output=$root.'/storage/app/private/release-packages';
if (!is_dir($output) && !mkdir($output,0700,true)) throw new RuntimeException('Cannot create output directory');
$name='vk-crm-uat-source-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.zip';
$zip=new ZipArchive();
if ($zip->open($output.'/'.$name,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Cannot create package');
$manifest=[];
$add=function(string $path,string $relative) use($zip,&$manifest):void {
    if (is_link($path)) throw new RuntimeException('Refusing symlink: '.$relative);
    if (!$zip->addFile($path,$relative)) throw new RuntimeException('Cannot add '.$relative);
    $manifest[$relative]=hash_file('sha256',$path);
};
try {
    foreach (['app','config','routes','resources','database/migrations','deployment/windows'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) throw new RuntimeException('Symlink in source');
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
    $zip->addFromString('PACKAGE-MANIFEST.json',json_encode(['type'=>'UAT_SOURCE_NOT_INSTALLER','release_version'=>'0.8.9','sqlserver_certified'=>false,'files'=>$manifest],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
} finally {$zip->close();}
$check=new ZipArchive();$check->open($output.'/'.$name);
foreach ($manifest as $relative=>$hash) if (!hash_equals($hash,hash('sha256',$check->getFromName($relative)))) throw new RuntimeException('Package verification failed: '.$relative);
$check->close();
echo $output.'/'.$name.PHP_EOL;
echo 'Verified files: '.count($manifest).PHP_EOL;
echo 'Source-only UAT package; NOT a production installer.'.PHP_EOL;
