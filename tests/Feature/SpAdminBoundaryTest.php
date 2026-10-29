<?php
namespace Tests\Feature;

use App\Models\{User,Role,Permission};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpAdminBoundaryTest extends TestCase
{
    use RefreshDatabase;
    public function test_admin_cannot_promote_itself_or_modify_chairman_even_by_direct_api():void
    {
        $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $reserved=Permission::firstOrCreate(['code'=>'dashboard.chairman.view'],['name'=>'Chủ tịch']);
        $role=Role::create(['tenant_id'=>$admin->tenant_id,'code'=>'ROLE-SPADMIN','name'=>'Chủ tịch','data_scope'=>'company']);$role->permissions()->sync(Permission::pluck('id'));
        $chair=User::create(['tenant_id'=>$admin->tenant_id,'name'=>'Chair','email'=>'chair@example.test','password'=>'Test-Chairman-Password','is_active'=>true]);$chair->roles()->sync([$role->id]);
        $admin->roles->first()->permissions()->syncWithoutDetaching([$reserved->id]);
        $login=$this->postJson('/api/v1/auth/login',['email'=>$admin->email,'password'=>'Admin@123'])->assertOk()->json('data');
        $this->assertFalse($login['user']['is_spadmin']);$this->assertNotContains('dashboard.chairman.view',$login['user']['permissions']);
        $token=$login['access_token'];
        $this->withToken($token)->getJson('/api/v1/roles')->assertOk()->assertJsonMissing(['code'=>'ROLE-SPADMIN'])->assertJsonMissing(['code'=>'dashboard.chairman.view']);
        $this->withToken($token)->postJson('/api/v1/users/'.$admin->id.'/roles',['role_ids'=>[$role->id]])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/users',['name'=>'Fake chairman','email'=>'fake-chair@example.test','password'=>'Random-Password-123','role_ids'=>[$role->id]])->assertForbidden();
        $this->assertDatabaseMissing('users',['email'=>'fake-chair@example.test']);
        $this->withToken($token)->postJson('/api/v1/roles',['name'=>'Giả Chủ tịch','data_scope'=>'company','permission_ids'=>[$reserved->id]])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/roles/'.$role->id.'/permissions',['permission_ids'=>[]])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/users/'.$chair->id.'/roles',['role_ids'=>[]])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/users/'.$chair->id.'/status',['is_active'=>false])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/users/'.$chair->id.'/organization',['department_id'=>null])->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/users/'.$chair->id.'/handover')->assertForbidden();
        $this->postJson('/api/v1/auth/login',['email'=>$chair->email,'password'=>'Test-Chairman-Password'])->assertOk()->assertJsonPath('data.user.is_spadmin',true);
        $this->assertTrue($chair->fresh()->is_active);
    }
}
