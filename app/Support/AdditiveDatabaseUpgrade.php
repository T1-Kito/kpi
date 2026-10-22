<?php
namespace App\Support;

use Illuminate\Database\Connection;

/** Only reviewed, nullable additions. Never runs arbitrary migration code. */
final class AdditiveDatabaseUpgrade
{
    public const STEPS=[
        '2026_10_20_100000_add_customer_feedback_details'=>['related_document'=>'string120','customer_confirmation'=>'string30'],
        '2026_10_20_110000_link_feedback_documents'=>['document_type'=>'string20','document_id'=>'bigint'],
        '2026_10_20_120000_create_ticket_attachments'=>['@new_table'=>'ticket_attachments'],
        '2026_10_22_100000_create_import_batch_tables'=>['@new_table'=>'import_sources','@membership'=>'import_source_users','@batches'=>'import_batches'],
    ];
    public function pending(Connection $db,array $required):array
    {
        if($db->getDriverName()==='sqlsrv' && in_array(mb_strtolower($db->getDatabaseName()),['master','model','msdb','tempdb'],true))throw new \RuntimeException('UPGRADE_BASELINE');
        if(!$db->getSchemaBuilder()->hasTable('migrations')) throw new \RuntimeException('UPGRADE_BASELINE');
        $applied=$db->table('migrations')->pluck('migration')->all();
        $pending=array_values(array_diff($required,$applied));
        if(array_diff($pending,array_keys(self::STEPS))) throw new \RuntimeException('UPGRADE_BASELINE');
        if($pending && !$db->getSchemaBuilder()->hasTable('service_tickets')) throw new \RuntimeException('UPGRADE_BASELINE');
        foreach($pending as $name) foreach(self::STEPS[$name] as $column=>$type) {
            if(str_starts_with($column,'@')) { if($db->getSchemaBuilder()->hasTable($type))throw new \RuntimeException('UPGRADE_SCHEMA_CONFLICT'); }
            elseif($db->getSchemaBuilder()->hasColumn('service_tickets',$column)) throw new \RuntimeException('UPGRADE_SCHEMA_CONFLICT');
        }
        return $pending;
    }
    public function apply(Connection $db,array $pending,?callable $fault=null):void
    {
        if(!in_array($db->getDriverName(),['sqlite','sqlsrv'],true) || array_diff($pending,array_keys(self::STEPS))) throw new \RuntimeException('UPGRADE_UNSUPPORTED');
        $db->transaction(function()use($db,$pending,$fault){
            $batch=(int)$db->table('migrations')->max('batch')+1;
            foreach($pending as $name) {
                if(isset(self::STEPS[$name]['@batches'])) {
                    self::importSchema($db);
                } elseif(isset(self::STEPS[$name]['@new_table'])) {
                    $db->getSchemaBuilder()->create('ticket_attachments',fn($table)=>self::evidenceSchema($table));
                } else {
                $db->getSchemaBuilder()->table('service_tickets',function($table)use($name){
                    foreach(self::STEPS[$name] as $column=>$type) {
                        if($type==='bigint')$table->unsignedBigInteger($column)->nullable();
                        else $table->string($column,(int)substr($type,6))->nullable();
                    }
                });
                }
                $db->table('migrations')->insert(['migration'=>$name,'batch'=>$batch]);
                if($fault)$fault($name);
            }
        });
    }
    public static function evidenceSchema(\Illuminate\Database\Schema\Blueprint $t):void
    {
        $t->id();$t->unsignedBigInteger('tenant_id');$t->unsignedBigInteger('service_ticket_id');$t->unsignedBigInteger('uploaded_by');
        $t->string('original_name',200);$t->string('path',255);$t->string('mime',40);$t->unsignedInteger('bytes');$t->string('sha256',64);$t->timestamps();
        $t->foreign('service_ticket_id')->references('id')->on('service_tickets');
        $t->index(['tenant_id','service_ticket_id']);
    }
    public static function importSchema(Connection $db):void
    {
        $db->getSchemaBuilder()->create('import_sources',function($t){
            $t->id();$t->unsignedBigInteger('tenant_id');$t->string('code',50);$t->string('name',150);$t->boolean('is_active')->default(true);$t->timestamps();
            $t->unique(['tenant_id','code']);
        });
        $db->getSchemaBuilder()->create('import_source_users',function($t){
            $t->id();$t->unsignedBigInteger('source_id');$t->unsignedBigInteger('user_id');$t->unique(['source_id','user_id']);
        });
        $db->getSchemaBuilder()->create('import_batches',function($t){
            $t->id();$t->unsignedBigInteger('tenant_id');$t->unsignedBigInteger('source_id');$t->unsignedBigInteger('user_id');
            $t->string('catalog',30);$t->string('file_hash',64);$t->string('original_name',200);$t->string('extension',5);$t->string('status',20);
            $t->text('result_json')->nullable();$t->longText('encrypted_payload')->nullable();$t->timestamp('expires_at');$t->timestamp('committed_at')->nullable();$t->timestamps();
            $t->index(['tenant_id','user_id','id']);$t->index(['tenant_id','source_id','catalog','file_hash']);
        });
    }
    public function backup(Connection $db,string $directory):string
    {
        $id='vkcrm-update-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));
        if($db->getDriverName()==='sqlite') {
            if(!is_dir($directory) && !mkdir($directory,0700,true))throw new \RuntimeException('UPGRADE_BACKUP');
            $path=$directory.'/'.$id.'.sqlite';
            $db->statement("VACUUM INTO '".str_replace("'","''",$path)."'");
            $copy=new \PDO('sqlite:'.$path);
            if($copy->query('PRAGMA integrity_check')->fetchColumn()!=='ok') throw new \RuntimeException('UPGRADE_BACKUP');
            $copy=null;
            file_put_contents($path.'.sha256',hash_file('sha256',$path),LOCK_EX);
            return $path;
        }
        if($db->getDriverName()!=='sqlsrv')throw new \RuntimeException('UPGRADE_UNSUPPORTED');
        // Server-side path, never the client's C: drive. Requires native backup
        // rights; failure stops before DDL rather than granting broader rights.
        $row=$db->selectOne("SELECT CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS nvarchar(4000)) AS backup_path");
        $folder=$row->backup_path ?? null;
        if(!$folder || str_contains($folder,"\0"))throw new \RuntimeException('UPGRADE_BACKUP_PATH');
        $separator=str_contains($folder,'\\') ? '\\' : '/';
        $path=rtrim($folder,'/\\').$separator.$id.'.bak';
        $database='['.str_replace(']',']]',$db->getDatabaseName()).']';
        $literal="N'".str_replace("'","''",$path)."'";
        self::executeNative($db,"BACKUP DATABASE $database TO DISK = $literal WITH COPY_ONLY, CHECKSUM");
        self::executeNative($db,"RESTORE VERIFYONLY FROM DISK = $literal WITH CHECKSUM");
        return $path;
    }
    public static function executeNative(Connection $db,string $sql):void
    {
        // BACKUP/RESTORE produce multiple informational results. Drain every
        // result before closing the cursor, otherwise PDO may cancel the work.
        $statement=$db->getPdo()->prepare($sql);
        try { $statement->execute(); while($statement->nextRowset()) {} }
        finally { $statement->closeCursor(); }
    }
}
