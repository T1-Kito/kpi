<?php
// Schema smoke test on a new synthetic local SQL database; never targets a client DB.
require __DIR__.'/../vendor/autoload.php';
$pdo=new PDO('sqlsrv:Server=lpc:localhost\\MSSQLSERVER01;Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=8',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT,30);
$name='VKCRM_ImportTrial_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!preg_match('/^VKCRM_ImportTrial_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/D',$name))throw new RuntimeException('Unsafe test target');
$pdo->exec('CREATE DATABASE ['.$name.']');$pdo->exec('USE ['.$name.']');
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.import_probe'=>['driver'=>'sqlsrv','host'=>'lpc:localhost\\MSSQLSERVER01','database'=>$name,'username'=>null,'password'=>null,'charset'=>'utf8','prefix'=>'','encrypt'=>'yes','trust_server_certificate'=>true]]);
Illuminate\Support\Facades\DB::setDefaultConnection('import_probe');
$db=Illuminate\Support\Facades\DB::connection('import_probe');$db->setPdo($pdo);
echo 'Synthetic database retained: '.$name.PHP_EOL;
$migration=require __DIR__.'/../database/migrations/2026_10_22_100000_create_import_batch_tables.php';$migration->up();
$check=function(bool $ok,string $message){if(!$ok)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message.PHP_EOL;};
foreach(['import_sources','import_source_users','import_batches'] as $table)$check($db->getSchemaBuilder()->hasTable($table),'schema '.$table);
$source=$db->table('import_sources')->insertGetId(['tenant_id'=>1,'code'=>'SYNTHETIC','name'=>'Nguồn kiểm thử tiếng Việt','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
$db->table('import_source_users')->insert(['source_id'=>$source,'user_id'=>1]);
$rejected=false;try{$db->table('import_source_users')->insert(['source_id'=>$source,'user_id'=>1]);}catch(Illuminate\Database\UniqueConstraintViolationException){$rejected=true;}
$check($rejected,'unique source member');
$payload=Illuminate\Support\Facades\Crypt::encryptString(base64_encode(str_repeat('Synthetic only.',80000)));
$id=$db->table('import_batches')->insertGetId(['tenant_id'=>1,'source_id'=>$source,'user_id'=>1,'catalog'=>'customers','file_hash'=>hash('sha256','synthetic'),'original_name'=>'kiểm-thử.xlsx','extension'=>'xlsx','status'=>'ready','result_json'=>json_encode(['total'=>1,'can_import'=>true]),'encrypted_payload'=>$payload,'expires_at'=>now()->addHours(2),'created_at'=>now(),'updated_at'=>now()]);
$check($db->table('import_batches')->where('id',$id)->value('encrypted_payload')===$payload,'large encrypted payload roundtrip');
$before=$db->table('import_batches')->count();
try{$db->transaction(function()use($db,$id){$db->table('import_batches')->where('id',$id)->lockForUpdate()->first();$db->table('import_batches')->where('id',$id)->update(['status'=>'committed','encrypted_payload'=>null]);throw new RuntimeException('Injected rollback');});}catch(RuntimeException $e){if($e->getMessage()!=='Injected rollback')throw $e;}
$check($db->table('import_batches')->count()===$before && $db->table('import_batches')->where('id',$id)->value('status')==='ready','transaction rollback retains ready batch');
echo 'SQL schema/storage smoke test only; not business UAT or concurrent-load certification.'.PHP_EOL;
