<?php
namespace Tests\Feature;

use App\Models\{Permission,Role,Tenant,User};
use App\Support\{DashboardPermissions,InitialAdministrator,SystemAccount};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstalledDashboardPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_installer_provisions_admin_and_chairman_dashboard_without_demo_seeder(): void
    {
        DB::table('permissions')->delete();
        $tenant=Tenant::create(['code'=>'CLEAN','name'=>'Clean install','status'=>'active']);
        $chairRole=Role::create(['tenant_id'=>$tenant->id,'code'=>SystemAccount::ROLE,'name'=>'Chủ tịch','data_scope'=>'company']);
        $chair=User::create(['tenant_id'=>$tenant->id,'name'=>'Chair','email'=>'chair-install@example.test','password'=>'Install-Test-123','is_active'=>true]);
        $chair->roles()->sync([$chairRole->id]);
        $admin=InitialAdministrator::create($tenant->id,'operator-install@example.test','Install-Test-456');
        $this->assertTrue($admin->hasPermission('dashboard.executive.view'));
        $this->assertFalse($admin->isSpAdmin());
        $this->assertFalse($admin->hasPermission(SystemAccount::PERMISSION));
        $this->assertTrue($chair->hasPermission('dashboard.executive.view'));
        $this->assertTrue($chair->hasPermission(SystemAccount::PERMISSION));
        $token=$this->postJson('/api/v1/auth/login',['email'=>$admin->email,'password'=>'Install-Test-456'])->assertOk()->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/dashboard/summary?type=admin')->assertOk()->assertJsonStructure(['data'=>['executive'=>['period','revenue']]]);
    }

    public function test_repair_is_repeatable_preserves_ordinary_roles_and_never_changes_accounts_or_scope(): void
    {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $ordinary=Role::create(['tenant_id'=>$admin->tenant_id,'code'=>'CUSTOM-STAFF','name'=>'Staff','data_scope'=>'own']);
        $ordinary->permissions()->sync(Permission::where('code','task.view')->pluck('id'));
        $before=$ordinary->permissions()->pluck('permissions.id')->all();
        $password=$admin->password;
        $executive=Permission::where('code','dashboard.executive.view')->firstOrFail();
        DB::table('role_permissions')->where('permission_id',$executive->id)->delete();
        $executive->delete();
        DashboardPermissions::install(DB::connection());
        $count=DB::table('role_permissions')->count();
        DashboardPermissions::install(DB::connection());
        $this->assertSame($count,DB::table('role_permissions')->count());
        $this->assertTrue($admin->fresh()->hasPermission('dashboard.executive.view'));
        $this->assertFalse($admin->fresh()->hasPermission(SystemAccount::PERMISSION));
        $this->assertSame($password,$admin->fresh()->password);
        $this->assertSame($before,$ordinary->permissions()->pluck('permissions.id')->all());
        $this->assertSame('own',$ordinary->fresh()->data_scope);
    }
}
