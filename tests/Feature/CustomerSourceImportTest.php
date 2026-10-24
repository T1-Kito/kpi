<?php
namespace Tests\Feature;
use App\Models\{Customer,User};
use App\Support\CustomerSourceImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerSourceImportTest extends TestCase
{
    use RefreshDatabase;
    private string $token;private int $source;
    protected function setUp():void {
        parent::setUp();$this->seed();$user=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $this->token=app(\App\Support\JwtTokenService::class)->issue($user);
        $this->source=$this->withToken($this->token)->postJson('/api/v1/import-center/sources',['code'=>'V1','name'=>'Thử phiên bản','is_active'=>true,'member_ids'=>[$user->id]])->assertCreated()->json('data.id');
    }
    private function file(int $revision,string $name='Khách phiên bản',string $phone='0900222333',bool $automatic=false,bool $noExternal=false):UploadedFile {
        $path=tempnam(sys_get_temp_dir(),'v1-test-');copy(resource_path('templates/import/khach-hang-theo-nguon-v1.xlsx'),$path);
        try{
            $zip=new \ZipArchive;$zip->open($path);$xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach([array_values(CustomerSourceImport::LABELS),['1','EXT-1',(string)$revision,$name,'Doanh nghiệp','', '',$phone,'v1@example.test','Hà Nội','Địa chỉ hóa đơn','Đại diện','Giám đốc','1000000','Hoạt động','']] as $i=>$values){
                if($noExternal && $i===1)$values[1]='';
                if($automatic)$values=array_values(array_filter($values,fn($key)=>!in_array($key,[0,2]),ARRAY_FILTER_USE_KEY));
                $xml.='<row r="'.($i+1).'">';foreach($values as $j=>$value)$xml.='<c r="'.chr(65+$j).($i+1).'" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1).'</t></is></c>';$xml.='</row>';
            }
            $zip->addFromString('xl/worksheets/sheet1.xml',$xml.'</sheetData></worksheet>');$zip->close();
            return UploadedFile::fake()->createWithContent('khach-nguon.xlsx',file_get_contents($path));
        }finally{unlink($path);}
    }
    private function preview(int $revision,string $name='Khách phiên bản',string $phone='0900222333') {
        return $this->withToken($this->token)->post('/api/v1/import-center/batches',['source_id'=>$this->source,'catalog'=>'customers-v1','file'=>$this->file($revision,$name,$phone)],['Accept'=>'application/json']);
    }
    public function test_new_customer_without_external_id_gets_crm_code_and_no_mapping():void {
        $id=$this->withToken($this->token)->post('/api/v1/import-center/customer-preview',['file'=>$this->file(1,automatic:true,noExternal:true)],['Accept'=>'application/json'])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $this->commit($id)->assertOk();$customer=Customer::where('email','v1@example.test')->firstOrFail();
        $this->assertStringStartsWith('CUS-',$customer->code);
        $this->assertDatabaseMissing('import_record_links',['catalog'=>'customers','record_id'=>$customer->id]);
        $this->commit($id)->assertOk()->assertJsonPath('replayed',true);
        $this->withToken($this->token)->post('/api/v1/import-center/customer-preview',['file'=>$this->file(1,'Tên thay đổi',automatic:true,noExternal:true)],['Accept'=>'application/json'])->assertCreated()->assertJsonPath('data.status','invalid');
    }
    public function test_staff_template_needs_no_technical_columns_and_conflicts_still_block():void {
        $preview=function(string $name){return $this->withToken($this->token)->post('/api/v1/import-center/customer-preview',['file'=>$this->file(1,$name,automatic:true)],['Accept'=>'application/json']);};
        $id=$preview('Khách tự động')->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$this->commit($id)->assertOk();
        $id=$preview('Tên mới tự động')->assertCreated()->assertJsonPath('data.result.preview.0.action','update')->json('data.id');$this->commit($id)->assertOk();
        $this->assertDatabaseHas('import_record_links',['catalog'=>'customers','source_revision'=>2]);
        Customer::where('email','v1@example.test')->update(['address'=>'Sửa tay']);
        $preview('Không ghi đè')->assertCreated()->assertJsonPath('data.status','invalid');
    }
    private function commit(int $id){return $this->withToken($this->token)->postJson('/api/v1/import-center/batches/'.$id.'/commit',['confirmed'=>true]);}
    public function test_template_requires_permission_and_catalog_is_available_after_schema_upgrade():void {
        $this->withHeader('Authorization','')->getJson('/api/v1/import-center/customer-template')->assertUnauthorized();
        $download=$this->withToken($this->token)->get('/api/v1/import-center/customer-template')->assertOk()->assertDownload();
        $this->assertStringContainsString(rawurlencode('Mẫu khách hàng.xlsx'),$download->headers->get('Content-Disposition'));
        $this->assertContains('customers-v1',$this->withToken($this->token)->getJson('/api/v1/import-center/sources')->assertOk()->json('meta.catalogs'));
    }
    public function test_simple_import_uses_default_source_and_saves_full_standard_fields():void {
        $response=$this->withToken($this->token)->post('/api/v1/import-center/customer-preview',['file'=>$this->file(1)],['Accept'=>'application/json'])->assertCreated()->assertJsonPath('data.status','ready');
        $id=$response->json('data.id');$this->commit($id)->assertOk();
        $customer=Customer::where('email','v1@example.test')->firstOrFail();
        $this->assertSame('Địa chỉ hóa đơn',$customer->billing_address);
        $this->assertSame('Đại diện',$customer->legal_representative);
        $this->assertSame('Giám đốc',$customer->representative_position);
        $this->assertSame(1000000.0,(float)$customer->credit_limit);
        $this->assertDatabaseHas('import_sources',['code'=>'CRM-CUSTOMER-DEFAULT']);
    }
    public function test_create_update_preserve_blanks_and_manual_conflict():void {
        $before=Customer::count();$batch=$this->preview(1)->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $this->assertSame($before,Customer::count());$this->commit($batch)->assertOk();
        $customer=Customer::where('email','v1@example.test')->firstOrFail();$this->assertDatabaseCount('import_record_links',1);
        $update=$this->preview(2,'Tên cập nhật','')->assertCreated()->assertJsonPath('data.result.preview.0.action','update')->assertJsonPath('data.result.preview.0.changes.name.before','Khách phiên bản')->json('data.id');
        $this->commit($update)->assertOk();$this->assertSame('0900222333',$customer->fresh()->phone);$this->assertSame('Tên cập nhật',$customer->fresh()->name);$this->assertSame($before+1,Customer::count());
        $this->preview(1,'Phiên bản cũ')->assertCreated()->assertJsonPath('data.status','invalid');
        $customer->update(['address'=>'Sửa thủ công']);
        $this->preview(3,'Không ghi đè')->assertCreated()->assertJsonPath('data.status','invalid');
        $this->assertSame('Tên cập nhật',$customer->fresh()->name);
    }
    public function test_change_after_preview_blocks_entire_commit():void {
        $id=$this->preview(1)->assertCreated()->json('data.id');$this->commit($id)->assertOk();
        $id=$this->preview(2,'Không lưu')->assertCreated()->json('data.id');
        Customer::where('email','v1@example.test')->update(['address'=>'Khác']);
        $this->commit($id)->assertUnprocessable();
        $this->assertDatabaseHas('import_record_links',['source_revision'=>1]);
        $this->assertDatabaseHas('customers',['email'=>'v1@example.test','name'=>'Khách phiên bản','address'=>'Khác']);
    }
    public function test_error_workbook_is_private_and_revoked_source_blocks_download():void {
        $id=$this->preview(1,'','')->assertCreated()->assertJsonPath('data.status','invalid')->json('data.id');
        $url='/api/v1/import-center/batches/'.$id.'/errors';
        $this->withHeader('Authorization','')->getJson($url)->assertUnauthorized();
        $response=$this->withToken($this->token)->get($url)->assertOk()->assertDownload('loi-nhap-danh-sach-'.$id.'.xlsx');
        $zip=new \ZipArchive;$this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname())===true);
        $xml=$zip->getFromName('xl/worksheets/sheet1.xml');$zip->close();
        $this->assertStringContainsString('Dòng trong file gốc',$xml);$this->assertStringNotContainsString('<f>',$xml);
        DB::table('import_source_users')->where('source_id',$this->source)->delete();
        $this->withToken($this->token)->getJson($url)->assertForbidden();
    }
    public function test_same_revision_changed_content_and_second_source_cannot_overwrite_customer():void {
        $id=$this->preview(1)->assertCreated()->json('data.id');$this->commit($id)->assertOk();
        $this->preview(1,'Nội dung khác')->assertCreated()->assertJsonPath('data.status','invalid');
        $user=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $this->source=$this->withToken($this->token)->postJson('/api/v1/import-center/sources',['code'=>'OTHER','name'=>'Nguồn khác','is_active'=>true,'member_ids'=>[$user->id]])->assertCreated()->json('data.id');
        $this->preview(2,'Không được nhận khách')->assertCreated()->assertJsonPath('data.status','invalid');
        $this->assertDatabaseCount('import_record_links',1);
        $this->assertDatabaseHas('customers',['email'=>'v1@example.test','name'=>'Khách phiên bản']);
    }
    public function test_failure_during_customer_update_rolls_back_mapping_and_business_record():void {
        $id=$this->preview(1)->assertCreated()->json('data.id');$this->commit($id)->assertOk();
        $id=$this->preview(2,'Tên không được lưu')->assertCreated()->json('data.id');
        $original=app(\App\Http\Controllers\Api\V1\CustomerController::class);
        $fault=\Mockery::mock($original)->makePartial();
        $fault->shouldReceive('update')->once()->andReturnUsing(function($request,$customer){$customer->update(['name'=>'Ghi dở dang']);throw new \RuntimeException('SYNTHETIC_WRITE_FAILURE');});
        $this->app->instance(\App\Http\Controllers\Api\V1\CustomerController::class,$fault);
        $this->commit($id)->assertStatus(500);
        $this->assertDatabaseHas('customers',['email'=>'v1@example.test','name'=>'Khách phiên bản']);
        $this->assertDatabaseHas('import_record_links',['source_revision'=>1]);
        $this->assertDatabaseHas('import_batches',['id'=>$id,'status'=>'ready']);
    }
}
