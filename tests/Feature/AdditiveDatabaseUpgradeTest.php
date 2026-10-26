<?php
namespace Tests\Feature;
use App\Support\AdditiveDatabaseUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdditiveDatabaseUpgradeTest extends TestCase
{
    private function fixture():array {
        $path=tempnam(sys_get_temp_dir(),'vkupgrade-');
        config(['database.connections.update_fixture'=>['driver'=>'sqlite','database'=>$path,'prefix'=>'','foreign_key_constraints'=>true]]);
        DB::purge('update_fixture');$db=DB::connection('update_fixture');
        $db->getSchemaBuilder()->create('migrations',fn($t)=>[$t->increments('id'),$t->string('migration'),$t->integer('batch')]);
        $db->getSchemaBuilder()->create('service_tickets',fn($t)=>[$t->increments('id'),$t->string('subject')]);
        $db->table('service_tickets')->insert(['subject'=>'Customer data retained']);
        $db->getSchemaBuilder()->create('customers',fn($t)=>[$t->increments('id'),$t->unsignedBigInteger('tenant_id')->default(1),$t->string('name')]);
        $db->getSchemaBuilder()->create('skus',fn($t)=>[$t->increments('id'),$t->unsignedBigInteger('tenant_id')->default(1)]);
        $db->getSchemaBuilder()->create('deals',fn($t)=>[$t->increments('id'),$t->string('name')]);
        $db->table('customers')->insert(['name'=>'Existing customer retained']);
        return [$db,$path];
    }
    public function test_backup_upgrade_and_repeat_preserve_data():void {
        [$db,$path]=$this->fixture();$service=new AdditiveDatabaseUpgrade;
        $required=array_keys(AdditiveDatabaseUpgrade::STEPS);$pending=$service->pending($db,$required);
        $backup=$service->backup($db,sys_get_temp_dir().'/VkCrmUpgradeTests');
        $this->assertSame(hash_file('sha256',$backup),file_get_contents($backup.'.sha256'));
        $copy=new \PDO('sqlite:'.$backup);$this->assertSame('Customer data retained',$copy->query('SELECT subject FROM service_tickets')->fetchColumn());
        $service->apply($db,$pending);
        $this->assertSame('Customer data retained',$db->table('service_tickets')->value('subject'));
        $this->assertTrue($db->getSchemaBuilder()->hasColumn('service_tickets','document_id'));
        $this->assertTrue($db->getSchemaBuilder()->hasTable('ticket_attachments'));
        foreach(['import_sources','import_source_users','import_batches'] as $table)$this->assertTrue($db->getSchemaBuilder()->hasTable($table));
        $this->assertTrue($db->getSchemaBuilder()->hasTable('import_record_links'));
        $this->assertTrue($db->getSchemaBuilder()->hasTable('crm_intake_rows'));
        foreach(['business_segment','customer_group','customer_audience'] as $column)$this->assertTrue($db->getSchemaBuilder()->hasColumn('customers',$column));
        $this->assertSame('Existing customer retained',$db->table('customers')->value('name'));
        $this->assertSame([],$service->pending($db,$required));
    }
    public function test_failure_rolls_back_schema_and_migration_ledger():void {
        [$db]=$this->fixture();$service=new AdditiveDatabaseUpgrade;
        try{$service->apply($db,array_keys(AdditiveDatabaseUpgrade::STEPS),fn()=>throw new \RuntimeException('FAULT'));$this->fail('Expected fault');}catch(\RuntimeException $e){$this->assertSame('FAULT',$e->getMessage());}
        $this->assertFalse($db->getSchemaBuilder()->hasColumn('service_tickets','related_document'));
        $this->assertSame(0,$db->table('migrations')->count());
        $this->assertSame('Customer data retained',$db->table('service_tickets')->value('subject'));
    }
    public function test_failure_after_table_creation_rolls_back_whole_upgrade():void {
        [$db]=$this->fixture();$service=new AdditiveDatabaseUpgrade;
        try{$service->apply($db,array_keys($service::STEPS),function($name){if(str_contains($name,'attachments'))throw new \RuntimeException('TABLE_FAULT');});$this->fail('Expected fault');}catch(\RuntimeException $e){$this->assertSame('TABLE_FAULT',$e->getMessage());}
        $this->assertFalse($db->getSchemaBuilder()->hasTable('ticket_attachments'));
        $this->assertFalse($db->getSchemaBuilder()->hasColumn('service_tickets','document_id'));
        $this->assertSame(0,$db->table('migrations')->count());
    }
    public function test_unknown_pending_migrations_fail_closed():void {
        [$db]=$this->fixture();$this->expectExceptionMessage('UPGRADE_BASELINE');
        (new AdditiveDatabaseUpgrade)->pending($db,['old_unreviewed_migration']);
    }
    public function test_import_failure_rolls_back_all_tables_and_ledger():void {
        [$db]=$this->fixture();$service=new AdditiveDatabaseUpgrade;
        try{$service->apply($db,array_keys($service::STEPS),function($name){if(str_contains($name,'import_batch'))throw new \RuntimeException('IMPORT_FAULT');});$this->fail('Expected fault');}catch(\RuntimeException $e){$this->assertSame('IMPORT_FAULT',$e->getMessage());}
        foreach(['import_sources','import_source_users','import_batches','ticket_attachments'] as $table)$this->assertFalse($db->getSchemaBuilder()->hasTable($table));
        $this->assertSame(0,$db->table('migrations')->count());
        $this->assertSame('Customer data retained',$db->table('service_tickets')->value('subject'));
    }
    public function test_failure_after_new_classification_rolls_back_every_new_schema_change():void {
        [$db]=$this->fixture();$service=new AdditiveDatabaseUpgrade;
        try{$service->apply($db,array_keys($service::STEPS),function($name){if(str_contains($name,'classification'))throw new \RuntimeException('LAST_STEP_FAULT');});$this->fail('Expected fault');}catch(\RuntimeException $e){$this->assertSame('LAST_STEP_FAULT',$e->getMessage());}
        $this->assertFalse($db->getSchemaBuilder()->hasTable('import_record_links'));
        $this->assertFalse($db->getSchemaBuilder()->hasColumn('customers','business_segment'));
        $this->assertSame('Existing customer retained',$db->table('customers')->value('name'));
        $this->assertSame(0,$db->table('migrations')->count());
    }
}
