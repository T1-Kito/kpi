<?php
namespace Tests\Feature;

use App\Models\{User,Customer};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportBatchTest extends TestCase
{
    use RefreshDatabase;
    private function setupImport():array {
        $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $token=$this->postJson('/api/v1/auth/login',['email'=>$admin->email,'password'=>'Admin@123'])->assertOk()->json('data.access_token');
        $source=$this->withToken($token)->postJson('/api/v1/import-center/sources',['code'=>'EXCEL-A','name'=>'Danh sách thử','is_active'=>true,'member_ids'=>[$admin->id]])->assertCreated()->json('data.id');
        return [$admin,$token,$source];
    }
    private function file():UploadedFile {return UploadedFile::fake()->createWithContent('khach-hang.csv',"name,customer_type,phone,email,tax_code,address,contact_name,identity_number\nKhách theo nguồn,organization,0900991234,batch@example.test,,Hồ Chí Minh,Anh An,\n");}
    public function test_source_metadata_edit_preserves_members_and_new_source_starts_without_members():void {
        [$admin,$token,$source]=$this->setupImport();
        $this->withToken($token)->putJson('/api/v1/import-center/sources/'.$source,['name'=>'Tên mới','is_active'=>true])->assertOk();
        $this->assertDatabaseHas('import_source_users',['source_id'=>$source,'user_id'=>$admin->id]);
        $new=$this->withToken($token)->postJson('/api/v1/import-center/sources',['code'=>'NO-MEMBERS','name'=>'Chưa cấp quyền','is_active'=>true])->assertCreated()->json('data.id');
        $this->assertDatabaseMissing('import_source_users',['source_id'=>$new]);
        $this->withToken($token)->putJson('/api/v1/import-center/sources/'.$source,['name'=>'Tên mới','is_active'=>true,'member_ids'=>[]])->assertOk();
        $this->assertDatabaseMissing('import_source_users',['source_id'=>$source]);
    }
    private function preview(string $token,int $source) {return $this->withToken($token)->post('/api/v1/import-center/batches',['source_id'=>$source,'catalog'=>'customers','file'=>$this->file()],['Accept'=>'application/json']);}
    private function directoryFile(string $kind,array $values):UploadedFile {
        $path=tempnam(sys_get_temp_dir(),'batch-template-');copy(resource_path('templates/import/'.$kind.'.xlsx'),$path);
        try{
            $zip=new \ZipArchive();$zip->open($path);$xml=$zip->getFromName('xl/worksheets/sheet1.xml');$row='<row xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" r="5">';
            foreach($values as $column=>$value)$row.='<c r="'.chr(65+$column).'5" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1).'</t></is></c>';
            $row.='</row>';$xml=preg_replace('~<(?:x:)?row\b[^>]*\br="5"[^>]*(?:/>|>.*?</(?:x:)?row>)~s',$row,$xml,1);$zip->addFromString('xl/worksheets/sheet1.xml',$xml);$zip->close();
            return UploadedFile::fake()->createWithContent('danh-sach.xlsx',file_get_contents($path));
        }finally{unlink($path);}
    }

    public function test_preview_commit_replay_and_payload_privacy():void {
        [$admin,$token,$source]=$this->setupImport();$before=Customer::count();
        $batch=$this->preview($token,$source)->assertCreated()->assertJsonPath('data.status','ready')->assertJsonMissingPath('data.encrypted_payload')->json('data');
        $this->assertSame($before,Customer::count());
        $payload=DB::table('import_batches')->where('id',$batch['id'])->value('encrypted_payload');$this->assertStringNotContainsString('batch@example.test',$payload);
        $url='/api/v1/import-center/batches/'.$batch['id'].'/commit';
        $this->withToken($token)->postJson($url,[])->assertUnprocessable();
        $response=$this->withToken($token)->postJson($url,['confirmed'=>true]);$this->assertSame(200,$response->status(),$response->getContent());$response->assertJsonPath('data.status','committed')->assertJsonPath('data.result.imported',1);
        $this->assertSame($before+1,Customer::count());
        $this->withToken($token)->postJson($url,['confirmed'=>true])->assertOk()->assertJsonPath('replayed',true);
        $this->preview($token,$source)->assertOk()->assertJsonPath('replayed',true)->assertJsonPath('data.id',$batch['id']);
        $this->assertSame($before+1,Customer::count());$this->assertNull(DB::table('import_batches')->where('id',$batch['id'])->value('encrypted_payload'));
    }
    public function test_revocation_expiry_and_changed_database_prevent_commit():void {
        [$admin,$token,$source]=$this->setupImport();$batch=$this->preview($token,$source)->assertCreated()->json('data');
        $url='/api/v1/import-center/batches/'.$batch['id'].'/commit';
        DB::table('import_source_users')->where('source_id',$source)->delete();$this->withToken($token)->postJson($url,['confirmed'=>true])->assertForbidden();
        DB::table('import_source_users')->insert(['source_id'=>$source,'user_id'=>$admin->id]);
        DB::table('import_batches')->where('id',$batch['id'])->update(['expires_at'=>now()->subMinute()]);$this->withToken($token)->postJson($url,['confirmed'=>true])->assertUnprocessable();
        DB::table('import_batches')->where('id',$batch['id'])->update(['expires_at'=>now()->addHour()]);
        Customer::create(['tenant_id'=>$admin->tenant_id,'code'=>'CHANGED-BETWEEN','name'=>'Đã tạo riêng','customer_type'=>'organization','phone'=>'0900991234','status'=>'active']);
        $before=Customer::count();$this->withToken($token)->postJson($url,['confirmed'=>true])->assertUnprocessable();$this->assertSame($before,Customer::count());
        $this->assertDatabaseHas('import_batches',['id'=>$batch['id'],'status'=>'ready']);
    }
    public function test_batch_owner_and_company_scope_are_enforced():void {
        [$admin,$token,$source]=$this->setupImport();$batch=$this->preview($token,$source)->assertCreated()->json('data');
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();$salesToken=app(\App\Support\JwtTokenService::class)->issue($sales);
        DB::table('import_source_users')->insert(['source_id'=>$source,'user_id'=>$sales->id]);
        $this->withToken($salesToken)->getJson('/api/v1/import-center/batches/'.$batch['id'])->assertNotFound();
        $this->withToken($salesToken)->postJson('/api/v1/import-center/batches/'.$batch['id'].'/commit',['confirmed'=>true])->assertNotFound();
        $this->preview($salesToken,$source)->assertForbidden();
        $this->withToken($salesToken)->getJson('/api/v1/import-center/batches')->assertOk()->assertJsonPath('meta.total',0);
        $this->withToken($token)->putJson('/api/v1/import-center/sources/'.$source,['code'=>'CHANGED','name'=>'Nguồn mới','is_active'=>true,'member_ids'=>[$admin->id]])->assertUnprocessable();
    }
    public function test_two_previews_cannot_commit_same_file_twice():void {
        [$admin,$token,$source]=$this->setupImport();$one=$this->preview($token,$source)->assertCreated()->json('data.id');$two=$this->preview($token,$source)->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/import-center/batches/'.$one.'/commit',['confirmed'=>true])->assertOk();
        $this->withToken($token)->postJson('/api/v1/import-center/batches/'.$two.'/commit',['confirmed'=>true])->assertConflict();
        $this->assertSame(1,Customer::where('email','batch@example.test')->count());
    }
    public function test_contacts_and_leads_use_original_business_controllers():void {
        [$admin,$token,$source]=$this->setupImport();$customer=Customer::where('customer_type','organization')->firstOrFail();
        foreach(['contacts'=>[$customer->code,'Người liên hệ theo nguồn','0900881234','contactbatch@example.test','Kế toán'],'leads'=>['Khách tiềm năng theo nguồn','0900885678','leadbatch@example.test','Hội chợ','CT-001']] as $kind=>$values){
            $batch=$this->withToken($token)->post('/api/v1/import-center/batches',['source_id'=>$source,'catalog'=>$kind,'file'=>$this->directoryFile($kind,$values)],['Accept'=>'application/json'])->assertCreated()->assertJsonPath('data.status','ready')->json('data');
            $this->withToken($token)->postJson('/api/v1/import-center/batches/'.$batch['id'].'/commit',['confirmed'=>true])->assertOk()->assertJsonPath('data.result.imported',1);
        }
        $this->assertDatabaseHas('customer_contacts',['email'=>'contactbatch@example.test','customer_id'=>$customer->id]);
        $this->assertDatabaseHas('leads',['email'=>'leadbatch@example.test','assigned_to'=>$admin->id]);
    }
    public function test_invalid_batches_never_commit_and_expired_payloads_are_pruned():void {
        [$admin,$token,$source]=$this->setupImport();
        $bad=UploadedFile::fake()->createWithContent('loi.csv',"name,customer_type,phone,email,tax_code,address,contact_name,identity_number\n,organization,0900771234,,,,,\n");
        $batch=$this->withToken($token)->post('/api/v1/import-center/batches',['source_id'=>$source,'catalog'=>'customers','file'=>$bad],['Accept'=>'application/json'])->assertCreated()->assertJsonPath('data.status','invalid')->json('data');
        $this->withToken($token)->postJson('/api/v1/import-center/batches/'.$batch['id'].'/commit',['confirmed'=>true])->assertUnprocessable();
        $ready=$this->preview($token,$source)->assertCreated()->json('data.id');
        DB::table('import_batches')->where('id',$ready)->update(['expires_at'=>now()->subMinute()]);
        $this->artisan('imports:prune')->assertSuccessful();
        $this->assertDatabaseHas('import_batches',['id'=>$ready,'status'=>'expired','encrypted_payload'=>null]);
        $this->assertDatabaseMissing('customers',['phone'=>'0900771234']);
    }
    public function test_foreign_source_and_members_and_disabled_sources_are_rejected():void {
        [$admin,$token,$source]=$this->setupImport();
        $tenant=\App\Models\Tenant::create(['code'=>'FOREIGN-IMPORT','name'=>'Đơn vị khác','status'=>'active']);
        $foreign=User::create(['tenant_id'=>$tenant->id,'name'=>'Người khác','email'=>'foreign-import@example.test','password'=>'Fixture-only-password','is_active'=>true]);
        $otherSource=DB::table('import_sources')->insertGetId(['tenant_id'=>$tenant->id,'code'=>'OTHER','name'=>'Nguồn khác','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $this->preview($token,$otherSource)->assertNotFound();
        $this->withToken($token)->putJson('/api/v1/import-center/sources/'.$source,['name'=>'Không hợp lệ','is_active'=>true,'member_ids'=>[$foreign->id]])->assertUnprocessable();
        $batch=$this->preview($token,$source)->assertCreated()->json('data.id');
        $this->withToken($token)->putJson('/api/v1/import-center/sources/'.$source,['name'=>'Đã ngừng','is_active'=>false,'member_ids'=>[$admin->id]])->assertOk();
        $this->withToken($token)->postJson('/api/v1/import-center/batches/'.$batch.'/commit',['confirmed'=>true])->assertUnprocessable();
        $this->assertDatabaseMissing('customers',['email'=>'batch@example.test']);
    }
}
