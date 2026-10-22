<?php

namespace Tests\Feature;

use App\Models\{Customer,Quotation,Sku,User};
use App\Support\{QuotationService,QuotationWorkflow};
use Illuminate\Database\SqlServerConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuotationRevisionSqlServerFixTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_creates_filtered_index_without_rewriting_or_deleting_quotes(): void
    {
        $connection=\Mockery::mock(SqlServerConnection::class)->makePartial();
        $connection->shouldReceive('getDriverName')->andReturn('sqlsrv');
        $connection->shouldReceive('getQueryGrammar')->andReturn(new \Illuminate\Database\Query\Grammars\SqlServerGrammar($connection));
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('getConfig')->with('prefix_indexes')->andReturn(false);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(fn($callback)=>$callback());
        $statements=[];
        $connection->shouldReceive('unprepared')->twice()->andReturnUsing(function($sql)use(&$statements){$statements[]=$sql;return true;});
        DB::shouldReceive('connection')->andReturn($connection);
        $migration=require database_path('migrations/2026_10_07_000006_fix_sqlserver_quotation_revision_unique.php');
        $migration->up();
        $this->assertStringContainsString('DROP INDEX',$statements[0]);
        $this->assertStringContainsString('CREATE UNIQUE INDEX',$statements[1]);
        $this->assertStringContainsString('WHERE [revision_root_id] IS NOT NULL',$statements[1]);
        $this->assertDoesNotMatchRegularExpression('/\b(DELETE|UPDATE|TRUNCATE|DROP TABLE)\b/i',implode(' ',$statements));
    }

    public function test_separate_roots_and_revision_copy_can_coexist_without_losing_original(): void
    {
        $this->seed();
        $actor=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $payload=['customer_id'=>Customer::firstOrFail()->id,'items'=>[['sku_id'=>Sku::firstOrFail()->id,'quantity'=>1,'unit_price'=>1000000,'vat_rate'=>0]]];
        $service=app(QuotationService::class);
        $first=$service->create($actor,$payload);
        $second=$service->create($actor,$payload);
        $this->assertNotEquals($first->id,$second->id);
        $this->assertNull($first->revision_root_id);
        $this->assertNull($second->revision_root_id);
        $first->update(['status'=>'approved']);
        $revision=app(QuotationWorkflow::class)->revise($first,$actor);
        $this->assertEquals($first->id,$revision->revision_root_id);
        $this->assertEquals(2,$revision->revision_number);
        $this->assertNotNull(Quotation::find($first->id));
        $third=$service->create($actor,$payload);
        $this->assertNull($third->revision_root_id);
    }
}
