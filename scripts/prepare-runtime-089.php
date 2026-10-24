<?php
$root=dirname(__DIR__);
$oldSource=new ZipArchive;$oldSource->open($root.'/storage/app/private/release-packages/vk-crm-uat-source-20261022-014732-f780b3.zip',ZipArchive::RDONLY);
$manifest=json_decode($oldSource->getFromName('PACKAGE-MANIFEST.json'),true,512,JSON_THROW_ON_ERROR);$oldSource->close();
if(!hash_equals($manifest['files']['composer.lock'],hash_file('sha256',$root.'/composer.lock')))throw new RuntimeException('Dependencies changed: full rebuild required');
$path=$root.'/storage/app/private/portable-payload-0.8.9.1.zip';
if(file_exists($path))throw new RuntimeException('Preserve existing runtime output');
if(!copy($root.'/storage/app/private/portable-payload.zip',$path))throw new RuntimeException('Cannot copy verified runtime');
$zip=new ZipArchive;if($zip->open($path)!==true)throw new RuntimeException('Cannot open runtime');
$extensions=['curl','fileinfo','gd','mbstring','openssl','pdo_sqlite','sqlite3','zip'];
$zip->addFile('C:/xampp/php/ext/php_opcache.dll','runtime/ext/php_opcache.dll');
$zip->addFile($root.'/deployment/installer/initialize-uat.php','initialize-uat.php');
$zip->addFromString('runtime/php.ini',"extension_dir=ext\ndate.timezone=Asia/Ho_Chi_Minh\nmemory_limit=256M\nzend_extension=php_opcache.dll\nopcache.enable=1\nopcache.enable_cli=1\nopcache.validate_timestamps=1\nopcache.revalidate_freq=0\n".implode("\n",array_map(fn($e)=>'extension=php_'.$e.'.dll',$extensions))."\n");
if(!$zip->close())throw new RuntimeException('Runtime archive failed');
echo $path.PHP_EOL;
