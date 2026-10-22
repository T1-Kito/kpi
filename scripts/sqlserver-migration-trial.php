<?php
// Creates only a uniquely named trial DB. Never uses or resets the configured CRM DB.
require __DIR__.'/../vendor/autoload.php';
$server='localhost';
foreach($argv as $argument)if(str_starts_with($argument,'--server='))$server=substr($argument,9);
if($server===''||preg_match('/[;{}\x00-\x1f]/',$server))throw new RuntimeException('Invalid server');
$free=disk_free_space(__DIR__.'/..');
if ($free===false || $free<5*1024**3) {
    fwrite(STDERR,"Trial stopped: at least 5 GiB of free workspace disk space is required. No new database was created by this run.\n");
    exit(1);
}
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!extension_loaded('pdo_sqlsrv')) {fwrite(STDERR,"Missing pdo_sqlsrv\n");exit(1);}
echo "Starting isolated SQL Server migration trial; live configuration is unchanged.\n";
$database='VKCRM_Trial_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
if (!preg_match('/^VKCRM_Trial_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/D',$database)) throw new RuntimeException('Invalid trial name');
$master=new PDO('sqlsrv:Server='.$server.';Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=5',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$master->exec('CREATE DATABASE ['.$database.']');
echo 'Trial database: '.$database.PHP_EOL;
config(['database.default'=>'sqlsrv','database.connections.sqlsrv'=>[
    'driver'=>'sqlsrv','host'=>$server,'database'=>$database,'username'=>null,'password'=>null,
    'charset'=>'utf8','prefix'=>'','prefix_indexes'=>true,'encrypt'=>'yes','trust_server_certificate'=>true,
    'port'=>null,'restrict_foreign_key_deletes'=>true,
]]);
Illuminate\Support\Facades\DB::purge('sqlsrv');
$code=Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'sqlsrv','--force'=>true]);
echo Illuminate\Support\Facades\Artisan::output();
echo "Trial database retained for inspection. Live .env was not changed.\n";
exit($code);
