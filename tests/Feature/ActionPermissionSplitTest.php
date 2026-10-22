<?php

namespace Tests\Feature;

use App\Models\{Customer,KpiAdjustment,Permission,Role,User};
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionPermissionSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function actor(array $codes): array
    {
        $user=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $role=Role::create(['tenant_id'=>$user->tenant_id,'code'=>'SPLIT-'.bin2hex(random_bytes(3)),'name'=>'Isolated task','data_scope'=>'company']);
        $role->permissions()->sync(Permission::whereIn('code',$codes)->pluck('id'));
        $user->roles()->sync([$role->id]);
        return [$user,app(JwtTokenService::class)->issue($user)];
    }

    public function test_customer_editor_cannot_edit_supplier_or_warehouse(): void
    {
        [, $token]=$this->actor(['customer.manage']);
        $this->withToken($token)->postJson('/api/v1/customers',['name'=>'Permitted customer'])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/suppliers',['name'=>'Forbidden supplier'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/warehouses',['name'=>'Forbidden warehouse'])->assertForbidden();
    }

    public function test_legacy_label_alone_does_not_implicitly_grant_split_actions(): void
    {
        [, $token]=$this->actor(['master.manage','task.create','alert.view','kpi.lock']);
        $this->withToken($token)->postJson('/api/v1/suppliers',['name'=>'No implicit grant'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/kpi/adjustments',[])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/kpi/definitions',[])->assertForbidden();
    }

    public function test_configuration_does_not_grant_kpi_closing_or_review(): void
    {
        [, $token]=$this->actor(['kpi.configure']);
        $this->withToken($token)->getJson('/api/v1/kpi/definitions')->assertOk();
        $this->withToken($token)->postJson('/api/v1/kpi/period-closures/close',[])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/kpi/adjustments',[])->assertForbidden();
    }

    public function test_independent_reviewer_can_decide_once_but_not_rewrite_decision(): void
    {
        [$reviewer,$token]=$this->actor(['kpi.adjustment.review']);
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $row=KpiAdjustment::create(['tenant_id'=>$admin->tenant_id,'user_id'=>$reviewer->id,'created_by'=>$admin->id,'adjustment_type'=>'bonus','points'=>3,'period_type'=>'month','period_start'=>now()->startOfMonth(),'period_end'=>now()->endOfMonth(),'reason'=>'Fixture','status'=>'pending']);
        $url='/api/v1/kpi/adjustments/'.$row->id.'/review';
        $this->withToken($token)->postJson($url,['status'=>'approved'])->assertOk();
        $this->withToken($token)->postJson($url,['status'=>'rejected'])->assertConflict();
        $this->assertSame('approved',$row->fresh()->status);
    }

    public function test_permission_migration_preserves_existing_actions_without_cross_role_grants(): void
    {
        $tenant=User::firstOrFail()->tenant_id;
        $legacy=Role::create(['tenant_id'=>$tenant,'code'=>'OLD-EDITOR','name'=>'Existing editor','data_scope'=>'own']);
        $legacy->permissions()->attach(Permission::where('code','master.manage')->value('id'));
        $viewer=Role::create(['tenant_id'=>$tenant,'code'=>'OLD-VIEWER','name'=>'Existing viewer','data_scope'=>'own']);
        $viewer->permissions()->attach(Permission::where('code','master.view')->value('id'));
        $migration=require database_path('migrations/2026_10_19_150000_split_action_permissions.php');
        $migration->up(); $migration->up();
        $this->assertSame(1,$legacy->permissions()->where('code','customer.manage')->count());
        $this->assertFalse($viewer->permissions()->where('code','customer.manage')->exists());
        $this->assertSame('own',$legacy->fresh()->data_scope);
    }
}
