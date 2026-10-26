<?php
// Read-only release verification; never opens workspace configuration or a customer database.
$root=dirname(__DIR__);
[$script,$source,$payload,$version]=$argv+[null,null,null,null];
if(!$source || !$payload || !$version)throw new RuntimeException('Usage: php scripts/verify-update-package.php SOURCE PAYLOAD VERSION');
$zip=new ZipArchive;
if($zip->open($source,ZipArchive::RDONLY)!==true)throw new RuntimeException('Source ZIP unreadable');
$manifest=json_decode($zip->getFromName('PACKAGE-MANIFEST.json'),true,512,JSON_THROW_ON_ERROR);
if($manifest['release_version']!==$version || $manifest['sqlserver_certified']!==false)throw new RuntimeException('Incorrect release metadata');
$seen=[];
for($i=0;$i<$zip->numFiles;$i++){
    $name=$zip->getNameIndex($i);
    if(isset($seen[$name]))throw new RuntimeException('Duplicate ZIP entry');$seen[$name]=true;
    if(str_ends_with($name,'/') || $name==='PACKAGE-MANIFEST.json')continue;
    $allowed=preg_match('~^(app/|config/|routes/|resources/|database/migrations/|deployment/windows/|public/assets/)~D',$name) || in_array($name,['artisan','composer.json','composer.lock','bootstrap/app.php','bootstrap/providers.php','public/index.php','public/.htaccess','public/robots.txt','public/favicon.ico'],true);
    if(!$allowed || str_contains($name,'..') || str_contains($name,'\\') || ($name!=='deployment/windows/.env.uat.example' && preg_match('~(^|/)(\.env(?:\..*)?|database\.sqlite|node_modules|\.git|logs|sessions|backups)(/|$)|\.(?:sqlite|bak|pdb)$|\.inspect\.ndjson$~i',$name)))throw new RuntimeException('Forbidden payload entry: '.$name);
    if(!isset($manifest['files'][$name]) || !hash_equals($manifest['files'][$name],hash('sha256',$zip->getFromName($name))))throw new RuntimeException('File checksum mismatch: '.$name);
}
foreach($manifest['files'] as $name=>$hash)if(!isset($seen[$name]))throw new RuntimeException('Missing manifest entry');
foreach(['app/Support/CrmIntakeImport.php','app/Support/CrmIntakeWorkbook.php','app/Http/Controllers/Api/V1/CrmIntakeController.php','app/Support/CatalogAccess.php','app/Support/CustomerGroupReference.php','public/assets/js/crm-intake.js','public/assets/css/crm-intake.css','public/assets/js/features/deals.js','resources/views/layouts/app.blade.php','database/migrations/2026_10_24_100000_add_catalog_sharing.php','database/migrations/2026_10_25_100000_create_customer_groups.php','database/migrations/2026_10_26_100000_add_deal_needs.php','database/migrations/2026_10_26_110000_create_service_interests.php','database/migrations/2026_10_26_120000_create_crm_intake_rows.php'] as $required)
    if(!isset($manifest['files'][$required]))throw new RuntimeException('Missing updated component: '.$required);
$zip->close();echo 'PASS source manifest, '.count($manifest['files']).' checksums, required modules and no local data/configuration.'.PHP_EOL;
if($zip->open($payload,ZipArchive::RDONLY)!==true)throw new RuntimeException('Runtime ZIP unreadable');
foreach(['runtime/php.exe','runtime/php.ini','runtime/ext/php_opcache.dll','vendor/autoload.php','initialize-uat.php'] as $required)if($zip->locateName($required)===false)throw new RuntimeException('Missing runtime component');
if(!str_contains($zip->getFromName('runtime/php.ini'),'opcache.validate_timestamps=1'))throw new RuntimeException('OPcache must detect updates');
$zip->close();
if(!hash_equals(trim(file_get_contents($root.'/deployment/installer/portable.sha256')),hash_file('sha256',$payload)))throw new RuntimeException('Embedded runtime checksum differs');
echo 'PASS runtime manifest requirements and embedded SHA256.'.PHP_EOL;
