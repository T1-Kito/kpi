<?php
// SQL compilation only: Laravel pretend intercepts database statements.
require __DIR__.'/../vendor/autoload.php';
$server='localhost';
foreach($argv as $argument)if(str_starts_with($argument,'--server='))$server=substr($argument,9);
if($server===''||preg_match('/[;{}\x00-\x1f]/',$server))throw new RuntimeException('Invalid server');
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlsrv','database.connections.sqlsrv'=>[
    // Metadata introspection can issue SELECT even inside pretend; use master read-only.
    'driver'=>'sqlsrv','host'=>$server,'database'=>'master','encrypt'=>'yes','trust_server_certificate'=>true,
    'username'=>null,'password'=>null,'charset'=>'utf8','prefix'=>'','prefix_indexes'=>true,
]]);
Illuminate\Support\Facades\DB::purge('sqlsrv');
$connection=Illuminate\Support\Facades\DB::connection('sqlsrv');
$queries=0;$passed=0;$failed=0;
foreach(glob(__DIR__.'/../database/migrations/*.php') as $file){
    try{
        $migration=require $file;
        $compiled=$connection->pretend(fn()=>$migration->up());
        $queries+=count($compiled);$passed++;
    }catch(Throwable $e){$failed++;echo 'FAIL '.basename($file).': '.$e->getMessage().PHP_EOL;}
}
echo "SQL Server compilation: $passed migration files, $queries statements, $failed failures.\n";
echo "No database created or changed. This does NOT verify execution, constraints or business compatibility.\n";
exit($failed?1:0);
