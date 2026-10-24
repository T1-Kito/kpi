<?php
namespace Tests\Feature;
use App\Models\{User,Customer,CustomerContact};
use App\Support\ContactSourceImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContactSourceImportTest extends TestCase
{
    use RefreshDatabase;
    private string $token;private int $source;private Customer $customer;
    protected function setUp():void {
        parent::setUp();$this->seed();$u=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->token=app(\App\Support\JwtTokenService::class)->issue($u);
        $this->source=$this->withToken($this->token)->postJson('/api/v1/import-center/sources',['code'=>'CONTACT','name'=>'Nguồn liên hệ','is_active'=>true,'member_ids'=>[$u->id]])->assertCreated()->json('data.id');
        $this->customer=Customer::where('tenant_id',$u->tenant_id)->where('customer_type','organization')->firstOrFail();$this->customer->update(['status'=>'active']);
    }
    private function preview(int $revision=1,string $name='Người theo nguồn',string $phone='0900998877',?string $reference=null,string $primary='',bool $twoPrimary=false,string $external='CONTACT-1'){
        $path=tempnam(sys_get_temp_dir(),'contact-v1-');copy(resource_path('templates/import/nguoi-lien-he-theo-nguon-v1.xlsx'),$path);
        try{$z=new \ZipArchive;$z->open($path);$xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach([array_values(ContactSourceImport::LABELS),['1',$external,(string)$revision,$reference ?? 'crm:'.$this->customer->code,$name,$phone,'contact-version@example.test','Kinh doanh',$primary]] as $i=>$values){$xml.='<row r="'.($i+1).'">';foreach($values as $j=>$v)$xml.='<c r="'.chr(65+$j).($i+1).'" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1).'</t></is></c>';$xml.='</row>';}
            if($twoPrimary){$xml.='<row r="3">';foreach(['1','CONTACT-2','1','crm:'.$this->customer->code,'Người thứ hai','0900001234','second-contact@example.test','Giám đốc','Có'] as $j=>$v)$xml.='<c r="'.chr(65+$j).'3" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1).'</t></is></c>';$xml.='</row>';}
            $z->addFromString('xl/worksheets/sheet1.xml',$xml.'</sheetData></worksheet>');$z->close();
            return $this->withToken($this->token)->post('/api/v1/import-center/batches',['source_id'=>$this->source,'catalog'=>'contacts-v1','file'=>UploadedFile::fake()->createWithContent('lien-he.xlsx',file_get_contents($path))],['Accept'=>'application/json']);
        }finally{unlink($path);}
    }
    private function commit(int $id){return $this->withToken($this->token)->postJson('/api/v1/import-center/batches/'.$id.'/commit',['confirmed'=>true]);}
    public function test_new_contact_without_external_id_is_created_without_mapping_and_duplicates_block():void {
        $id=$this->preview(external:'')->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $this->commit($id)->assertOk();
        $contact=CustomerContact::where('email','contact-version@example.test')->firstOrFail();
        $this->assertDatabaseMissing('import_record_links',['catalog'=>'contacts','record_id'=>$contact->id]);
        $this->commit($id)->assertOk()->assertJsonPath('replayed',true);
        $this->preview(name:'Tên khác',external:'')->assertCreated()->assertJsonPath('data.status','invalid');
    }
    public function test_primary_contact_accepts_vietnamese_and_rejects_invalid_choice():void {
        $this->preview(primary:'khác')->assertCreated()->assertJsonPath('data.status','invalid');
        $id=$this->preview(primary:'Có')->assertCreated()->assertJsonPath('data.result.preview.0.is_primary',true)->json('data.id');
        $this->commit($id)->assertOk();
        $this->assertDatabaseHas('customer_contacts',['email'=>'contact-version@example.test','is_primary'=>true]);
        $this->assertSame('Người theo nguồn',$this->customer->fresh()->contact_name);
        $id=$this->preview(2,'Đổi tên chính',primary:'')->assertCreated()->json('data.id');$this->commit($id)->assertOk();
        $this->assertSame('Đổi tên chính',$this->customer->fresh()->contact_name);
    }
    public function test_two_primary_contacts_in_one_customer_block_entire_batch():void {
        $before=CustomerContact::count();$id=$this->preview(primary:'Có',twoPrimary:true)->assertCreated()->assertJsonPath('data.status','invalid')->json('data.id');
        $this->commit($id)->assertUnprocessable();$this->assertSame($before,CustomerContact::count());
    }
    public function test_simple_import_uses_standard_template_payload():void {
        $id=$this->preview(primary:'Có')->assertCreated()->json('data.id');
        $batch=DB::table('import_batches')->where('id',$id)->first();
        $bytes=base64_decode(\Illuminate\Support\Facades\Crypt::decryptString($batch->encrypted_payload));
        $id=$this->withToken($this->token)->post('/api/v1/import-center/contact-preview',['file'=>UploadedFile::fake()->createWithContent('Mẫu người liên hệ.xlsx',$bytes)],['Accept'=>'application/json'])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $this->commit($id)->assertOk();
        $this->assertDatabaseHas('customer_contacts',['customer_id'=>$this->customer->id,'email'=>'contact-version@example.test','is_primary'=>true]);
        $this->assertDatabaseHas('import_sources',['code'=>'CRM-CUSTOMER-DEFAULT']);
    }
    public function test_template_and_catalog_are_available_to_authorized_admin():void {
        $this->withHeader('Authorization','')->getJson('/api/v1/import-center/contact-template')->assertUnauthorized();
        $response=$this->withToken($this->token)->get('/api/v1/import-center/contact-template')->assertOk()->assertDownload();
        $this->assertStringContainsString(rawurlencode('Mẫu người liên hệ.xlsx'),$response->headers->get('Content-Disposition'));
        $this->assertContains('contacts-v1',$this->withToken($this->token)->getJson('/api/v1/import-center/sources')->assertOk()->json('meta.catalogs'));
    }
    public function test_preview_create_update_preserves_customer_and_blank_phone():void {
        $before=CustomerContact::count();$id=$this->preview()->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$this->assertSame($before,CustomerContact::count());
        $this->commit($id)->assertOk();$contact=CustomerContact::where('email','contact-version@example.test')->firstOrFail();$this->assertSame($this->customer->id,$contact->customer_id);
        $id=$this->preview(2,'Tên mới','')->assertCreated()->assertJsonPath('data.result.preview.0.action','update')->json('data.id');$this->commit($id)->assertOk();
        $this->assertSame('Tên mới',$contact->fresh()->name);$this->assertSame('0900998877',$contact->fresh()->phone);$this->assertSame($before+1,CustomerContact::count());$this->assertDatabaseHas('import_record_links',['catalog'=>'contacts','source_revision'=>2]);
        $this->commit($id)->assertOk()->assertJsonPath('replayed',true);
    }
    public function test_missing_reference_and_manual_edit_block_writes():void {
        $this->preview(reference:'ext:unknown')->assertCreated()->assertJsonPath('data.status','invalid');
        $id=$this->preview()->assertCreated()->json('data.id');$this->commit($id)->assertOk();
        $id=$this->preview(2,'Không ghi')->assertCreated()->json('data.id');CustomerContact::where('email','contact-version@example.test')->update(['name'=>'Sửa tay']);
        $this->commit($id)->assertUnprocessable();$this->assertDatabaseHas('import_record_links',['catalog'=>'contacts','source_revision'=>1]);$this->assertDatabaseHas('customer_contacts',['email'=>'contact-version@example.test','name'=>'Sửa tay']);
    }
    public function test_external_parent_reference_works_only_in_same_source():void {
        $u=User::where('email','admin@vk-kpi.local')->firstOrFail();
        DB::table('import_record_links')->insert(['tenant_id'=>$u->tenant_id,'source_id'=>$this->source,'catalog'=>'customers','external_id'=>'parent-1','record_id'=>$this->customer->id,'source_revision'=>1,'source_hash'=>str_repeat('a',64),'crm_hash'=>str_repeat('b',64)]);
        $id=$this->preview(reference:'ext:PARENT-1')->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$this->commit($id)->assertOk();
        $this->assertDatabaseHas('customer_contacts',['customer_id'=>$this->customer->id,'email'=>'contact-version@example.test']);
    }
    public function test_import_cannot_move_existing_contact_to_another_customer():void {
        $id=$this->preview()->assertCreated()->json('data.id');$this->commit($id)->assertOk();
        $other=Customer::create(['tenant_id'=>$this->customer->tenant_id,'code'=>'CUS-OTHER-TRIAL','name'=>'Khách khác','customer_type'=>'organization','status'=>'active','sales_owner_id'=>$this->customer->sales_owner_id]);
        $this->preview(2,reference:'crm:'.$other->code)->assertCreated()->assertJsonPath('data.status','invalid');
        $this->assertDatabaseHas('customer_contacts',['email'=>'contact-version@example.test','customer_id'=>$this->customer->id]);
    }
    public function test_mid_write_failure_rolls_back_contact_mapping_and_batch():void {
        $id=$this->preview()->assertCreated()->json('data.id');$this->commit($id)->assertOk();
        $id=$this->preview(2,'Tên không lưu')->assertCreated()->json('data.id');
        $original=app(\App\Http\Controllers\Api\V1\CustomerController::class);$fault=\Mockery::mock($original)->makePartial();
        $fault->shouldReceive('updateContact')->once()->andReturnUsing(function($request,$customer,$contact){$contact->update(['name'=>'Ghi dở']);throw new \RuntimeException('CONTACT_SYNTHETIC_FAILURE');});
        $this->app->instance(\App\Http\Controllers\Api\V1\CustomerController::class,$fault);
        $this->commit($id)->assertStatus(500);
        $this->assertDatabaseHas('customer_contacts',['email'=>'contact-version@example.test','name'=>'Người theo nguồn']);
        $this->assertDatabaseHas('import_record_links',['catalog'=>'contacts','source_revision'=>1]);
        $this->assertDatabaseHas('import_batches',['id'=>$id,'status'=>'ready']);
    }
}
