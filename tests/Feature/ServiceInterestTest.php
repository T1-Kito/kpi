<?php
namespace Tests\Feature;
use App\Models\{ServiceInterest,User};
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ServiceInterestTest extends TestCase
{
    use RefreshDatabase;
    public function test_original_file_service_catalog_preview_commit_repeat_and_snapshot():void
    {
        $path='C:/Users/Admin/Downloads/NHẬP LIỆU - PHÂN LOẠI (1).xlsx';if(!is_file($path))$this->markTestSkipped();
        $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->withToken(app(JwtTokenService::class)->issue($admin));
        $preview=function()use($path){return $this->post('/api/v1/service-interests/import',['file'=>new \Illuminate\Http\UploadedFile($path,basename($path),null,null,true)],['Accept'=>'application/json']);};
        $p=$preview()->assertOk()->assertJsonCount(11,'data.rows')->assertJsonPath('data.create_count',17);
        $this->assertSame(0,ServiceInterest::count());
        $this->postJson('/api/v1/service-interests/import',['token'=>$p->json('data.token')])->assertOk()->assertJsonPath('data.created',17);
        $this->assertSame(6,ServiceInterest::whereNull('parent_id')->count());
        $this->postJson('/api/v1/service-interests/import',['token'=>$p->json('data.token')])->assertConflict();
        $preview()->assertOk()->assertJsonPath('data.create_count',0);
    }
    public function test_catalog_two_levels_selection_tenant_and_inactive_guards():void
    {
        $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();$sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $this->withToken(app(JwtTokenService::class)->issue($admin));
        $parent=$this->postJson('/api/v1/service-interests',['name'=>'Dịch vụ đoàn','is_active'=>true])->assertCreated()->json('data.id');
        $child=$this->postJson('/api/v1/service-interests',['name'=>'Suất ăn','parent_id'=>$parent,'is_active'=>true])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/service-interests',['name'=>'Cấp 3','parent_id'=>$child,'is_active'=>true])->assertUnprocessable();
        $this->postJson('/api/v1/service-interests',['name'=>'suất ăn','parent_id'=>$parent,'is_active'=>true])->assertUnprocessable();
        $foreign=ServiceInterest::create(['tenant_id'=>999,'name'=>'Khác công ty','is_active'=>true]);
        $this->getJson('/api/v1/service-interests')->assertOk()->assertJsonCount(2,'data');
        $this->withToken(app(JwtTokenService::class)->issue($sales));
        $this->postJson('/api/v1/service-interests',['name'=>'Cấm','is_active'=>true])->assertForbidden();
        $this->postJson('/api/v1/deals',['name'=>'Sai dịch vụ','needs'=>['service_ids'=>[$foreign->id]]])->assertUnprocessable();
        $row=$this->postJson('/api/v1/deals',['name'=>'Chương trình','needs'=>['service_ids'=>[$child],'interests'=>['Giả mạo']]])->assertCreated()->assertJsonPath('data.needs.interests.0','Dịch vụ đoàn / Suất ăn')->json('data');
        ServiceInterest::whereKey($parent)->update(['is_active'=>false]);
        $this->postJson('/api/v1/deals',['name'=>'Ngừng dùng','needs'=>['service_ids'=>[$child]]])->assertUnprocessable();
        $this->putJson('/api/v1/deals/'.$row['id'].'/needs',['version'=>$row['version'],'needs'=>['service_ids'=>[$child],'purpose'=>'Giữ lựa chọn cũ']])->assertOk();
        $this->assertDatabaseMissing('deals',['name'=>'Sai dịch vụ']);
        $this->assertDatabaseMissing('deals',['name'=>'Ngừng dùng']);
    }
}
