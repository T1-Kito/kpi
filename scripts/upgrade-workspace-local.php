<?php
// Explicitly scoped upgrade of the development SQLite database, never customer SQL.
if (PHP_SAPI!=='cli' || !in_array($argv[1] ?? '',['--trial','--apply'],true)) exit(1);
$apply=$argv[1]==='--apply';
$root=realpath(__DIR__.'/..');
$normalize=fn($path)=>strtolower(str_replace('\\','/',$path));
if ($normalize($root)!=='c:/users/admin/downloads/vk-kpi') exit(1);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::connection();
$database=realpath($db->getDatabaseName());
if (!app()->environment('local') || $db->getDriverName()!=='sqlite' || !$database
    || $normalize($database)!==$normalize($root.'/database/database.sqlite')) {
    fwrite(STDERR,"Refused: target is not the named workspace-local SQLite database.\n"); exit(1);
}
$tables=['users','customers','customer_contacts','leads','deals','quotations','sales_orders','sales_invoices',
    'customer_payments','purchase_requests','purchase_orders','goods_receipts','goods_issues',
    'inventory_balances','tasks','contracts','service_tickets','warranty_claims'];
$counts=function () use ($tables) {
    $counts=[];
    foreach ($tables as $table) $counts[$table]=Illuminate\Support\Facades\DB::table($table)->count();
    return $counts;
};
$check=function ($expected) use ($counts) {
    $connection=Illuminate\Support\Facades\DB::connection();
    if ($counts()!==$expected) throw new RuntimeException('Business record counts changed unexpectedly');
    if ($connection->getPdo()->query('PRAGMA integrity_check')->fetchColumn()!=='ok') throw new RuntimeException('Integrity check failed');
    if ($connection->getPdo()->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Foreign-key check failed');
    if (!Illuminate\Support\Facades\Schema::hasColumn('users','token_version')
        || !Illuminate\Support\Facades\Schema::hasTable('revoked_access_tokens')) throw new RuntimeException('Authentication schema missing');
    $admin=App\Models\User::where('email','admin@vk-kpi.local')->firstOrFail();
    $tokens=app(App\Support\JwtTokenService::class);
    $tokens->verify($tokens->issue($admin));
};
$original=Illuminate\Support\Facades\DB::getDefaultConnection();
$maintenanceStarted=false;
$result=0;
try {
    if ($apply) {
        if (app()->isDownForMaintenance()) throw new RuntimeException('Application was already in maintenance; refusing to change that state');
        if (Illuminate\Support\Facades\Artisan::call('down')!==0) throw new RuntimeException('Cannot enter maintenance');
        $maintenanceStarted=true;
    }
    $before=$counts();
    $backups=app(App\Support\LocalDatabaseBackup::class);
    $name=$backups->create();
    $backups->verify($name);
    $copy=$backups->restoreCopy($name);
    echo 'VERIFIED_BACKUP '.$backups->directory().'/'.$name.PHP_EOL;
    $config=config('database.connections.'.$original);
    $config['database']=$copy; $config['url']=null;
    config(['database.connections.upgrade_trial'=>$config]);
    Illuminate\Support\Facades\DB::setDefaultConnection('upgrade_trial');
    $exit=Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'upgrade_trial','--force'=>true]);
    echo Illuminate\Support\Facades\Artisan::output();
    if ($exit!==0) throw new RuntimeException('Trial migration failed');
    $check($before);
    echo "TRIAL_PASSED: record counts, SQLite integrity, foreign keys and session verification.\n";
    Illuminate\Support\Facades\DB::purge('upgrade_trial');
    Illuminate\Support\Facades\DB::setDefaultConnection($original);
    if ($apply) {
        $exit=Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>$original,'--force'=>true]);
        echo Illuminate\Support\Facades\Artisan::output();
        if ($exit!==0) throw new RuntimeException('Local migration failed; verified backup retained');
        $check($before);
        echo "LOCAL_UPGRADE_PASSED: data counts preserved and sessions verify.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR,'UPGRADE_STOPPED: '.$error->getMessage().PHP_EOL);
    $result=1;
} finally {
    Illuminate\Support\Facades\DB::setDefaultConnection($original);
    if ($maintenanceStarted) Illuminate\Support\Facades\Artisan::call('up');
}
exit($result);
