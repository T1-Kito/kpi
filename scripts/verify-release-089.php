<?php
// Verify only the two explicitly selected release payloads; never read local configuration/data.
$root=dirname(__DIR__);
$source=$root.'/storage/app/private/release-packages/vk-crm-uat-source-20261023-092330-ad0a8d.zip';
$zip=new ZipArchive;if($zip->open($source,ZipArchive::RDONLY)!==true)throw new RuntimeException('Source ZIP unreadable');
$manifest=json_decode($zip->getFromName('PACKAGE-MANIFEST.json'),true,512,JSON_THROW_ON_ERROR);
if($manifest['release_version']!=='0.8.9')throw new RuntimeException('Wrong release');
foreach($manifest['files'] as $name=>$hash){
    if($name!=='deployment/windows/.env.uat.example' && preg_match('~(^|/)(\.env(?:\..*)?|database\.sqlite|node_modules|\.git)(/|$)|\.inspect\.ndjson$~i',$name))throw new RuntimeException('Forbidden payload entry: '.$name);
    if(!hash_equals($hash,hash('sha256',$zip->getFromName($name))))throw new RuntimeException('Hash mismatch: '.$name);
}
foreach(['app/Support/OrganizationSourceImport.php','app/Support/ImportedUserActivation.php','app/Support/AdditiveDatabaseUpgrade.php','resources/templates/import/users-v1.xlsx','database/migrations/2026_10_22_110000_create_import_record_links.php','database/migrations/2026_10_22_120000_add_customer_business_classification.php','public/assets/branding/vk-crm.ico'] as $name)if(!isset($manifest['files'][$name]))throw new RuntimeException('Missing entry: '.$name);
echo 'PASS 0.8.9 source hashes and '.count($manifest['files']).' allowlisted files; required new modules, templates and migrations included.'.PHP_EOL;
$zip->close();
$payload=$root.'/storage/app/private/portable-payload-0.8.9.1.zip';
if($zip->open($payload,ZipArchive::RDONLY)!==true)throw new RuntimeException('Runtime ZIP unreadable');
foreach(['runtime/php.exe','runtime/php.ini','runtime/ext/php_opcache.dll','vendor/autoload.php','initialize-uat.php'] as $name)if($zip->locateName($name)===false)throw new RuntimeException('Missing runtime: '.$name);
if(!str_contains($zip->getFromName('runtime/php.ini'),'opcache.validate_timestamps=1'))throw new RuntimeException('OPcache timestamp checks missing');
$zip->close();echo 'PASS runtime archive and OPcache update-safe configuration.'.PHP_EOL;
