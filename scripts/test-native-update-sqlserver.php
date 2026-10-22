<?php
// Creates two uniquely named synthetic databases on the local test instance only.
// Keeps fixtures/backups for inspection. Never reads a client SQL connection.
require __DIR__.'/../vendor/autoload.php';
$testPdo=new PDO('sqlsrv:Server=lpc:localhost\\MSSQLSERVER01;Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=8',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$testPdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT,45);
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$id=gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
$name='VKCRM_UpdateTrial_'.$id;$restored='VKCRM_UpdateRestore_'.$id;
echo json_encode(['trial'=>$name,'restore'=>$restored,'synthetic_only'=>true],JSON_UNESCAPED_SLASHES)."\n";
$base=['driver'=>'sqlsrv','host'=>'lpc:localhost\\MSSQLSERVER01','port'=>null,'username'=>null,'password'=>null,'charset'=>'utf8','prefix'=>'','encrypt'=>'yes','trust_server_certificate'=>true,'login_timeout'=>15];
config(['database.connections.update_master'=>$base+['database'=>'master']]);
$master=Illuminate\Support\Facades\DB::connection('update_master');
$master->setPdo($testPdo);
$master->getPdo()->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT,45);
foreach([$name,$restored] as $target) if(!preg_match('/^VKCRM_Update(?:Trial|Restore)_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/D',$target))throw new RuntimeException('Unsafe test target');
$quote=fn($s)=>"N'".str_replace("'","''",$s)."'";
$master->unprepared('CREATE DATABASE ['.$name.']');
config(['database.connections.update_trial'=>$base+['database'=>$name]]);
$db=Illuminate\Support\Facades\DB::connection('update_trial');
// One physical local connection avoids opening multiple sessions on this
// memory-constrained workstation. Database names are validated above.
$db->setPdo($master->getPdo());$db->unprepared('USE ['.$name.']');
$db->getSchemaBuilder()->create('migrations',function($t){$t->increments('id');$t->string('migration');$t->integer('batch');});
$db->getSchemaBuilder()->create('service_tickets',function($t){$t->id();$t->string('subject');});
$db->table('service_tickets')->insert(['subject'=>'Synthetic customer data retained']);
$service=new App\Support\AdditiveDatabaseUpgrade;$steps=array_keys($service::STEPS);
$backup=$service->backup($db,'');echo "PASS native backup and VERIFYONLY\n";
try{$service->apply($db,$steps,function($name){if(str_contains($name,'import_batch'))throw new RuntimeException('INJECTED');});throw new RuntimeException('Expected injected failure');}
catch(RuntimeException $e){if($e->getMessage()!=='INJECTED')throw $e;}
if($db->getSchemaBuilder()->hasColumn('service_tickets','related_document') || $db->getSchemaBuilder()->hasTable('ticket_attachments') || $db->table('migrations')->count()!==0)throw new RuntimeException('Rollback failed');
echo "PASS SQL transactional DDL and ledger rollback\n";
foreach(['import_sources','import_source_users','import_batches'] as $table)if($db->getSchemaBuilder()->hasTable($table))throw new RuntimeException('Import rollback failed');
$service->apply($db,$steps);
if($service->pending($db,$steps)!==[] || $db->table('service_tickets')->value('subject')!=='Synthetic customer data retained')throw new RuntimeException('Upgrade failed');
echo "PASS additive upgrade and business data retained\n";
foreach(['import_sources','import_source_users','import_batches'] as $table)if(!$db->getSchemaBuilder()->hasTable($table))throw new RuntimeException('Import table missing');
$db->table('import_sources')->insert(['tenant_id'=>1,'code'=>'TRIAL','name'=>'Synthetic import source','is_active'=>true]);
if($db->table('import_sources')->value('code')!=='TRIAL')throw new RuntimeException('Import write failed');
echo "PASS three import tables, writes and full rollback verified\n";
$files=$master->select('RESTORE FILELISTONLY FROM DISK = '.$quote($backup));
$paths=$master->selectOne("SELECT CAST(SERVERPROPERTY('InstanceDefaultDataPath') AS nvarchar(4000)) AS data_path, CAST(SERVERPROPERTY('InstanceDefaultLogPath') AS nvarchar(4000)) AS log_path");
$moves=[];
foreach($files as $i=>$file){
    if(!in_array($file->Type,['D','L'],true))throw new RuntimeException('Unexpected file type');
    $folder=$file->Type==='L'?$paths->log_path:$paths->data_path;
    if(!$folder)throw new RuntimeException('Missing restore directory');
    $target=rtrim($folder,'/\\').'\\'.$restored.'_'.$i.($file->Type==='L'?'.ldf':'.mdf');
    $moves[]='MOVE '.$quote($file->LogicalName).' TO '.$quote($target);
}
App\Support\AdditiveDatabaseUpgrade::executeNative($master,'RESTORE DATABASE ['.$restored.'] FROM DISK = '.$quote($backup).' WITH '.implode(', ',$moves).', CHECKSUM, RECOVERY');
config(['database.connections.update_restore'=>$base+['database'=>$restored]]);
$copy=Illuminate\Support\Facades\DB::connection('update_restore');
$copy->setPdo($master->getPdo());$copy->unprepared('USE ['.$restored.']');
if($copy->table('service_tickets')->value('subject')!=='Synthetic customer data retained' || $copy->table('migrations')->count()!==0 || $copy->getSchemaBuilder()->hasColumn('service_tickets','related_document'))throw new RuntimeException('Restore mismatch');
echo "PASS native restore to second isolated database, original schema and data verified\n";
echo json_encode(['trial'=>$name,'restore'=>$restored,'backup'=>$backup,'retained_for_inspection'=>true],JSON_UNESCAPED_SLASHES)."\n";
