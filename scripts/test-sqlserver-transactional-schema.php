<?php
// Local SQL Server only. Unique table prefix; all DDL/data rolled back, never COMMIT.
require __DIR__.'/../vendor/autoload.php';
$server='localhost\\MSSQLSERVER01';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!extension_loaded('pdo_sqlsrv'))throw new RuntimeException('Missing driver');
$prefix='vkprobe_'.bin2hex(random_bytes(5)).'_';
config(['database.default'=>'sqlsrv','database.connections.sqlsrv'=>[
    'driver'=>'sqlsrv','host'=>$server,'port'=>null,'database'=>'tempdb',
    'encrypt'=>'yes','trust_server_certificate'=>true,'username'=>null,'password'=>null,
    'charset'=>'utf8','prefix'=>$prefix,'prefix_indexes'=>true,'login_timeout'=>5,
    'restrict_foreign_key_deletes'=>in_array('--restrict-foreign-keys',$argv,true),
]]);
Illuminate\Support\Facades\DB::purge('sqlsrv');
$connection=Illuminate\Support\Facades\DB::connection('sqlsrv');
$started=false;$failed=false;
try{
    $connection->getPdo()->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT,15);
    $connection->beginTransaction();$started=true;
    $files=glob(__DIR__.'/../database/migrations/*.php');sort($files);
    if(!in_array('--all',$argv,true))$files=array_slice($files,0,1);
    foreach($files as $file){
        echo 'RUN '.basename($file).PHP_EOL;
        $migration=require $file;$migration->up();
        echo 'PASS '.basename($file).PHP_EOL;
    }
}catch(Throwable $e){
    $failed=true;
    // This isolated schema test has no customer input, passwords or real business rows.
    echo 'FAIL '.$e->getMessage().PHP_EOL;
}finally{
    if($started){
        while($connection->transactionLevel()>0)$connection->rollBack();
        $remaining=$connection->selectOne('SELECT COUNT(*) AS n FROM sys.tables WHERE name LIKE ?',[$prefix.'%']);
        if((int)$remaining->n!==0)throw new RuntimeException('Rollback verification failed');
        echo "ROLLBACK verified: no probe tables retained.\n";
    }else echo "No transaction or schema creation started.\n";
    Illuminate\Support\Facades\DB::disconnect('sqlsrv');
}
exit($failed?1:0);
