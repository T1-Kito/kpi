<?php
// Read-only compatibility gate: no migrations, seeds, password resets or .env writes.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !in_array(count($argv),[3,4],true)) exit(2);
try {
    [$script, $root, $stage] = $argv;
    $root = realpath($root); $stage = realpath($stage);
    if (!$root || !$stage || !is_file($root.'/.env')) throw new RuntimeException('INSTALLATION');
    chdir($root);
    $loader = require $root.'/vendor/autoload.php';
    $loader->setPsr4('App\\', $stage.'/app');
    // Production Composer uses an optimized class map, which takes priority over PSR-4.
    // Redirect it too; otherwise this check could accidentally load the old application.
    $map = [];
    foreach ($loader->getClassMap() as $class => $path) {
        if (str_starts_with($class, 'App\\')) $map[$class] = $stage.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    }
    $loader->addClassMap($map);
    $loader->setClassMapAuthoritative(false);
    $app = require $stage.'/bootstrap/app.php';
    $app->useEnvironmentPath($root);
    $app->useStoragePath($root.'/storage');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->configurationIsCached()) throw new RuntimeException('CACHED_CONFIG');
    $db = $app->make('db')->connection();
    if (!in_array($db->getDriverName(), ['sqlite','sqlsrv'], true)) throw new RuntimeException('DRIVER');
    $db->select('SELECT 1 AS vk_update_check');
    if (!$db->getSchemaBuilder()->hasTable('migrations')) throw new RuntimeException('SCHEMA');
    $applied = $db->table('migrations')->pluck('migration')->all();
    $required=array_map(fn($file)=>pathinfo($file,PATHINFO_FILENAME),glob($stage.'/database/migrations/*.php'));
    $pending=array_values(array_diff($required,$applied));
    if($pending) {
        if(!class_exists(\App\Support\AdditiveDatabaseUpgrade::class))throw new RuntimeException('MIGRATION_REQUIRED');
        $upgrade=new \App\Support\AdditiveDatabaseUpgrade;
        if(array_diff($pending,array_keys($upgrade::STEPS)))throw new RuntimeException('MIGRATION_REQUIRED');
        $upgrade->pending($db,$required);
        if($db->getDriverName()==='sqlsrv')$db->getPdo()->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT,120);
        if(($argv[3] ?? '')==='--upgrade-reviewed') {
            $down=$root.'/storage/framework/down';
            if(!is_file($down) || !str_contains(file_get_contents($down),'VK_CRM_SOFTWARE_UPDATE'))throw new RuntimeException('UPGRADE_MAINTENANCE');
            try{$backup=$upgrade->backup($db,$root.'/storage/app/private/database-backups');}
            catch(Throwable $failure){throw new RuntimeException('UPGRADE_BACKUP_FAILED');}
            // Retain server-side backup location for the operator; no passwords.
            $record=$root.'/storage/app/private/database-backups';
            if(!is_dir($record) && !mkdir($record,0700,true))throw new RuntimeException('UPGRADE_BACKUP_RECORD');
            $receipt=json_encode(['database'=>$db->getDatabaseName(),'driver'=>$db->getDriverName(),'backup'=>$backup,'verified_at'=>gmdate('c'),'pending'=>$pending],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
            if(file_put_contents($record.'/upgrade-'.bin2hex(random_bytes(8)).'.json',$receipt,LOCK_EX)===false)throw new RuntimeException('UPGRADE_BACKUP_RECORD');
            $upgrade->apply($db,$pending);
        } else { echo 'VK_UPDATE_ADDITIVE_REQUIRED';exit(0); }
    }
    echo 'VK_UPDATE_COMPATIBLE';
} catch (Throwable $error) {
    // Never return a connection string, SQL password or raw driver exception.
    $code = in_array($error->getMessage(), ['INSTALLATION','DRIVER','SCHEMA','MIGRATION_REQUIRED','CACHED_CONFIG','UPGRADE_BACKUP_FAILED','UPGRADE_BACKUP_RECORD','UPGRADE_BASELINE','UPGRADE_SCHEMA_CONFLICT','UPGRADE_MAINTENANCE'], true) ? $error->getMessage() : 'CONNECTION_OR_BOOTSTRAP';
    if (getenv('VK_UPDATE_DIAGNOSTICS') === '1') {
        fwrite(STDERR, $code.PHP_EOL);
        for ($detail=$error; $detail; $detail=$detail->getPrevious()) {
            $message=$detail->getMessage();
            // Do not print SQL query bindings, credentials or connection strings.
            $message=preg_split('/\s*\(Connection:/',$message,2)[0];
            $message=preg_replace('/\b(?:PWD|Password|UID|User ID)\s*=\s*[^;\r\n]*/i','[credential hidden]',$message);
            fwrite(STDERR,get_class($detail).': '.$message.PHP_EOL.$detail->getFile().':'.$detail->getLine().PHP_EOL);
        }
    } else fwrite(STDERR, $code);
    exit(1);
}
