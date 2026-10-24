<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportRecordLinkTest extends TestCase
{
    use RefreshDatabase;
    private function values(array $extra=[]): array {
        return array_replace(['tenant_id'=>1,'source_id'=>1,'catalog'=>'customers','external_id'=>'CUS-EXT-001','record_id'=>1,'source_revision'=>1,'source_hash'=>str_repeat('a',64),'crm_hash'=>str_repeat('b',64)],$extra);
    }
    public function test_identity_is_partitioned_by_tenant_source_and_catalog(): void {
        foreach([[],['tenant_id'=>2],['source_id'=>2,'record_id'=>2],['catalog'=>'contacts']] as $extra)DB::table('import_record_links')->insert($this->values($extra));
        $this->assertSame(4,DB::table('import_record_links')->count());
        $this->assertSame(1,DB::table('import_record_links')->where('tenant_id',1)->where('source_id',1)->where('catalog','customers')->where('external_id','CUS-EXT-001')->count());
    }
    public function test_duplicate_external_identity_is_rejected(): void {
        DB::table('import_record_links')->insert($this->values());
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('import_record_links')->insert($this->values(['record_id'=>2]));
    }
    public function test_another_source_cannot_claim_same_crm_record(): void {
        DB::table('import_record_links')->insert($this->values());
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('import_record_links')->insert($this->values(['source_id'=>2,'external_id'=>'OTHER']));
    }
    public function test_failed_batch_does_not_leave_identity_mapping(): void {
        try{DB::transaction(function(){DB::table('import_record_links')->insert($this->values());throw new \RuntimeException('TRIAL_FAILURE');});}
        catch(\RuntimeException $e){$this->assertSame('TRIAL_FAILURE',$e->getMessage());}
        $this->assertSame(0,DB::table('import_record_links')->count());
    }
}
