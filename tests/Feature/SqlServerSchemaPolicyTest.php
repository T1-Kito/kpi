<?php

namespace Tests\Feature;

use App\Support\SqlServerInstallConnection;
use App\Support\SqlServerRestrictGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SqlServerGrammar;
use Illuminate\Support\Fluent;
use Tests\TestCase;

class SqlServerSchemaPolicyTest extends TestCase
{
    public function test_explicit_sql_install_policy_keeps_foreign_keys_but_restricts_cascades(): void
    {
        $connection=new SqlServerInstallConnection(fn()=>throw new \LogicException('No connection expected'),'test','', ['restrict_foreign_key_deletes'=>true]);
        $connection->useDefaultSchemaGrammar();
        $blueprint=new Blueprint($connection,'departments');
        $command=new Fluent(['name'=>'foreign','index'=>'parent_fk','columns'=>['parent_id'],'on'=>'departments','references'=>['id'],'onDelete'=>'set null','onUpdate'=>'cascade']);
        $sql=$connection->getSchemaGrammar()->compileForeign($blueprint,$command);
        $this->assertInstanceOf(SqlServerRestrictGrammar::class,$connection->getSchemaGrammar());
        $this->assertStringContainsString('foreign key ("parent_id") references "departments" ("id")',$sql);
        $this->assertStringContainsString('on delete no action on update no action',$sql);
        $this->assertSame('set null',$command->onDelete,'Do not mutate migration command');
        $this->assertSame('cascade',$command->onUpdate);
    }

    public function test_existing_connections_do_not_silently_change_policy(): void
    {
        $before=config('database.default');
        $connection=new SqlServerInstallConnection(fn()=>throw new \LogicException('No connection expected'),'test','', []);
        $connection->useDefaultSchemaGrammar();
        $this->assertSame(SqlServerGrammar::class,get_class($connection->getSchemaGrammar()));
        $this->assertSame($before,config('database.default'));
    }

    public function test_entire_first_migration_compiles_without_cascade_and_without_connecting(): void
    {
        config(['database.connections.sqlsrv'=>['driver'=>'sqlsrv','database'=>'not_a_real_database','prefix'=>'','restrict_foreign_key_deletes'=>true]]);
        \Illuminate\Support\Facades\DB::purge('sqlsrv');
        $connection=\Illuminate\Support\Facades\DB::connection('sqlsrv');
        $connection->setPdo(fn()=>throw new \LogicException('Compilation must not connect'));
        $connection->setReadPdo(fn()=>throw new \LogicException('Compilation must not connect'));
        $oldDefault=\Illuminate\Support\Facades\DB::getDefaultConnection();
        \Illuminate\Support\Facades\DB::setDefaultConnection('sqlsrv');
        try{
            $migration=require database_path('migrations/0001_01_01_000000_create_users_table.php');
            $statements=$connection->pretend(fn()=>$migration->up());
            $foreignKeys=array_filter(array_column($statements,'query'),fn($sql)=>str_contains($sql,'foreign key'));
            $this->assertGreaterThan(10,count($foreignKeys));
            foreach($foreignKeys as $sql){
                $this->assertStringContainsString('on delete no action',$sql);
                $this->assertStringNotContainsString('on delete set null',$sql);
                $this->assertStringNotContainsString('on delete cascade',$sql);
            }
        }finally{\Illuminate\Support\Facades\DB::setDefaultConnection($oldDefault);\Illuminate\Support\Facades\DB::purge('sqlsrv');}
    }
}
