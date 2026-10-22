<?php
require __DIR__.'/../vendor/autoload.php';
$root=dirname(__DIR__);$output=$root.'/storage/app/private/portable-payload.zip';
if(file_exists($output))throw new RuntimeException('Payload already exists; choose a new release name.');
$zip=new ZipArchive();if($zip->open($output,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Cannot create payload');
// Local, known runtime for isolated evaluation only; not an approved production runtime.
foreach(glob('C:/xampp/php/*') as $file)if(is_file($file) && (str_ends_with($file,'.dll') || in_array(basename($file),['php.exe','license.txt','readme-redist-bins.txt','snapshot.txt'])))$zip->addFile($file,'runtime/'.basename($file));
$extensions=['curl','fileinfo','gd','mbstring','openssl','pdo_sqlite','sqlite3','zip'];
foreach($extensions as $extension){$file='C:/xampp/php/ext/php_'.$extension.'.dll';if(is_file($file))$zip->addFile($file,'runtime/ext/'.basename($file));}
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/vendor',FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile()&&!$file->isLink())$zip->addFile($file->getPathname(),'vendor/'.str_replace('\\','/',substr($file->getPathname(),strlen($root.'/vendor/'))));
$zip->addFile($root.'/deployment/installer/initialize-uat.php','initialize-uat.php');
$zip->addFromString('runtime/php.ini',"extension_dir=ext\ndate.timezone=Asia/Ho_Chi_Minh\nmemory_limit=256M\n".implode("\n",array_map(fn($e)=>'extension=php_'.$e.'.dll',$extensions))."\n");
if(!$zip->close())throw new RuntimeException('Cannot finish payload');
echo $output.PHP_EOL;
