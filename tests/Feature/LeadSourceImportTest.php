<?php
namespace Tests\Feature;
use App\Models\{User,Lead,Task};
use App\Support\LeadSourceImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class LeadSourceImportTest extends TestCase
{
    use RefreshDatabase;
    private string $token;private int $source;private User $actor;
    protected function setUp():void {
        parent::setUp();$this->seed();$this->actor=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->token=app(\App\Support\JwtTokenService::class)->issue($this->actor);
        $this->source=$this->withToken($this->token)->postJson('/api/v1/import-center/sources',['code'=>'LEAD','name'=>'Nguồn tiềm năng','is_active'=>true,'member_ids'=>[$this->actor->id]])->assertCreated()->json('data.id');
    }
    private function preview(int $revision=1,string $name='Tiềm năng nguồn',string $phone='0900333999',string $external='LEAD-EXT-1',string $owner='',string $customer='',bool $simple=false){
        $path=tempnam(sys_get_temp_dir(),'lead-v1-');copy(resource_path('templates/import/khach-tiem-nang-theo-nguon-v1.xlsx'),$path);
        try{$z=new \ZipArchive;$z->open($path);$xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach([array_values(LeadSourceImport::LABELS),['1',$external,(string)$revision,$name,$phone,'version-lead@example.test','Hội chợ','FAIR-01',$owner,$customer]] as $i=>$values){if($simple)$values=array_values(array_filter($values,fn($key)=>!in_array($key,[0,2],true),ARRAY_FILTER_USE_KEY));$xml.='<row r="'.($i+1).'">';foreach($values as $j=>$v)$xml.='<c r="'.chr(65+$j).($i+1).'" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1).'</t></is></c>';$xml.='</row>';}
            $z->addFromString('xl/worksheets/sheet1.xml',$xml.'</sheetData></worksheet>');$z->setMtimeName('xl/worksheets/sheet1.xml',946684800);$z->close();
            return $this->withToken($this->token)->post($simple?'/api/v1/import-center/lead-preview':'/api/v1/import-center/batches',['source_id'=>$this->source,'catalog'=>'leads-v1','file'=>UploadedFile::fake()->createWithContent('tiem-nang.xlsx',file_get_contents($path))],['Accept'=>'application/json']);
        }finally{unlink($path);}
    }
    private function commit($id){return $this->withToken($this->token)->postJson('/api/v1/import-center/batches/'.$id.'/commit',['confirmed'=>true]);}
    public function test_simple_template_without_technical_columns_or_external_id_generates_code_and_followup():void {
        $id=$this->preview(external:'',simple:true)->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $this->assertDatabaseMissing('leads',['email'=>'version-lead@example.test']);
        $this->commit($id)->assertOk();$lead=Lead::where('email','version-lead@example.test')->firstOrFail();
        $this->assertNotEmpty($lead->code);$this->assertSame($this->actor->id,$lead->assigned_to);
        $this->assertDatabaseMissing('import_record_links',['catalog'=>'leads','record_id'=>$lead->id]);
        $this->commit($id)->assertOk()->assertJsonPath('replayed',true);
        $this->assertSame(1,Task::where('source_type','Lead')->where('source_id',$lead->id)->count());
        $this->preview(name:'Không ghép trùng',external:'',simple:true)->assertCreated()->assertJsonPath('data.status','invalid');
    }
    public function test_assignment_and_customer_reference_are_checked_before_any_write():void {
        $owner=User::where('tenant_id',$this->actor->tenant_id)->where('is_active',true)->where('id','!=',$this->actor->id)->firstOrFail();
        $customer=\App\Models\Customer::where('tenant_id',$this->actor->tenant_id)->where('customer_type','organization')->whereNull('merged_into_id')->firstOrFail();$customer->update(['status'=>'active']);
        $this->preview(owner:'unknown@example.test')->assertCreated()->assertJsonPath('data.status','invalid');
        $this->preview(customer:'crm:NOT-FOUND')->assertCreated()->assertJsonPath('data.status','invalid');
        $id=$this->preview(owner:$owner->email,customer:'crm:'.$customer->code,simple:true)->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $this->commit($id)->assertOk();$lead=Lead::where('email','version-lead@example.test')->firstOrFail();
        $this->assertSame($owner->id,$lead->assigned_to);$this->assertSame($customer->id,$lead->customer_id);
        $this->assertDatabaseHas('tasks',['source_type'=>'Lead','source_id'=>$lead->id,'assignee_id'=>$owner->id]);
    }
    public function test_template_and_catalog_require_authorization():void {
        $this->withHeader('Authorization','')->getJson('/api/v1/import-center/lead-template')->assertUnauthorized();
        $response=$this->withToken($this->token)->get('/api/v1/import-center/lead-template')->assertOk()->assertDownload();
        $this->assertStringContainsString(rawurlencode('Mẫu khách hàng tiềm năng.xlsx'),$response->headers->get('Content-Disposition'));
        $this->assertContains('leads-v1',$this->withToken($this->token)->getJson('/api/v1/import-center/sources')->assertOk()->json('meta.catalogs'));
    }
    public function test_inactive_assignee_after_preview_blocks_entire_commit():void {
        $owner=User::where('tenant_id',$this->actor->tenant_id)->where('is_active',true)->where('id','!=',$this->actor->id)->firstOrFail();
        $id=$this->preview(owner:$owner->email,simple:true)->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $owner->update(['is_active'=>false]);$this->commit($id)->assertUnprocessable();
        $this->assertDatabaseMissing('leads',['email'=>'version-lead@example.test']);
        $this->assertDatabaseHas('import_batches',['id'=>$id,'status'=>'ready']);
    }
    public function test_preview_create_assign_and_replay_do_not_duplicate_followup():void {
        $before=Lead::count();$tasks=Task::count();$id=$this->preview()->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $this->assertSame($before,Lead::count());$this->assertSame($tasks,Task::count());$this->commit($id)->assertOk();
        $lead=Lead::where('email','version-lead@example.test')->firstOrFail();$this->assertSame($this->actor->id,$lead->assigned_to);
        $this->assertSame(1,Task::where('source_type','Lead')->where('source_id',$lead->id)->count());
        $this->commit($id)->assertOk()->assertJsonPath('replayed',true);$this->preview()->assertOk()->assertJsonPath('replayed',true);
        $this->assertSame($before+1,Lead::count());$this->assertSame(1,Task::where('source_type','Lead')->where('source_id',$lead->id)->count());
    }
    public function test_excel_updates_and_same_revision_different_content_are_blocked():void {
        $id=$this->preview()->assertCreated()->json('data.id');$this->commit($id)->assertOk();
        $this->preview(2,'Không cập nhật')->assertCreated()->assertJsonPath('data.status','invalid');
        $this->preview(1,'Khác nội dung')->assertCreated()->assertJsonPath('data.status','invalid');
        $this->assertDatabaseHas('leads',['email'=>'version-lead@example.test','name'=>'Tiềm năng nguồn']);$this->assertDatabaseHas('import_record_links',['catalog'=>'leads','source_revision'=>1]);
    }
    public function test_duplicates_added_after_preview_block_commit():void {
        $id=$this->preview()->assertCreated()->json('data.id');
        Lead::create(['tenant_id'=>$this->actor->tenant_id,'code'=>'LEAD-CONFLICT','name'=>'Có trước xác nhận','phone'=>'0900333999','status'=>'new','assigned_to'=>$this->actor->id]);
        $this->commit($id)->assertUnprocessable();$this->assertDatabaseMissing('import_record_links',['catalog'=>'leads']);
        $this->assertDatabaseMissing('leads',['email'=>'version-lead@example.test']);
    }
    public function test_failure_after_business_write_rolls_back_lead_task_and_mapping():void {
        $id=$this->preview()->assertCreated()->json('data.id');$before=Lead::count();$tasks=Task::count();
        $original=app(\App\Http\Controllers\Api\V1\LeadController::class);$fault=\Mockery::mock($original)->makePartial();
        $fault->shouldReceive('store')->once()->andReturnUsing(function($request)use($original){$original->store($request);throw new \RuntimeException('LEAD_SYNTHETIC_FAILURE');});
        $this->app->instance(\App\Http\Controllers\Api\V1\LeadController::class,$fault);$this->commit($id)->assertStatus(500);
        $this->assertSame($before,Lead::count());$this->assertSame($tasks,Task::count());$this->assertDatabaseMissing('import_record_links',['catalog'=>'leads']);$this->assertDatabaseHas('import_batches',['id'=>$id,'status'=>'ready']);
    }
}
