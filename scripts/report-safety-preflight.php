<?php
// CLI-only, read-only by default. Never prints credentials or raw SQL errors.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$installedRoot = $argv[1] ?? '';
if (!is_dir($installedRoot) || !is_file($installedRoot.'/artisan')) throw new RuntimeException('Invalid application root.');
require $installedRoot.'/vendor/autoload.php';
require_once dirname(__DIR__).'/app/Support/BusinessIdentity.php';
$app = require $installedRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $db=Illuminate\Support\Facades\DB::connection();
    $pdo=$db->getPdo();
    $supplierKeys=[]; $warrantyKeys=[]; $duplicates=['suppliers'=>0,'warranties'=>0];
    foreach ($db->table('suppliers')->orderBy('id')->cursor() as $row) {
        $tax=App\Support\BusinessIdentity::tax($row->tax_code);
        if ($tax===null) continue;
        $key=$row->tenant_id.':'.$tax;
        if(isset($supplierKeys[$key])) $duplicates['suppliers']++;
        $supplierKeys[$key]=true;
    }
    foreach($db->table('warranty_claims')->orderBy('id')->cursor() as $row) {
        $key=$row->tenant_id.':'.App\Support\BusinessIdentity::warranty($row->product_name ?? '',$row->serial_number ?? '');
        if(isset($warrantyKeys[$key])) $duplicates['warranties']++;
        $warrantyKeys[$key]=true;
    }
    $driver=$db->getDriverName();
    $result=['driver'=>$driver,'database'=>$db->getDatabaseName(),'users'=>$db->table('users')->count(),'duplicate_groups'=>$duplicates,'security_migration_applied'=>$db->getSchemaBuilder()->hasColumn('users','token_version')];
    if ($driver==='sqlsrv') {
        $path=(array)$db->selectOne("SELECT CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS nvarchar(4000)) AS path");
        $result['native_backup_directory_available']=!empty($path['path']);
    }
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $error) {
    $state = $error->errorInfo[0] ?? null;
    $native = $error->errorInfo[1] ?? null;
    fwrite(STDERR,json_encode(['status'=>'PREFLIGHT_FAILED','error'=>get_class($error),'sqlstate'=>is_string($state)&&preg_match('/^[A-Z0-9]{5}$/D',$state)?$state:null,'native'=>is_numeric($native)?(int)$native:null]).PHP_EOL);
    exit(1);
}
