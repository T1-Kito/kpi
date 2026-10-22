<?php
$zip=new ZipArchive();$path=dirname(__DIR__).'/storage/app/private/portable-payload.zip';
if($zip->open($path)!==true)throw new RuntimeException('Missing payload');
$ini=$zip->getFromName('runtime/php.ini');
$zip->addFromString('runtime/php.ini',str_replace("extension=php_bcmath.dll\n",'',$ini));
if(!$zip->close())throw new RuntimeException('Cannot update runtime config');
echo "Built-in bcmath no longer loaded as DLL.\n";
