<?php

namespace Tests\Feature;

use App\Models\{Customer, Permission, Quotation, Role, Sku, User};
use App\Support\{QuotationService, QuotationWorkflow};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesApprovalPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['email'=>$user->email, 'password'=>'Admin@123'])->assertOk()->json('data.access_token');
    }

    private function reviewer(User $admin): User
    {
        $role=Role::create(['tenant_id'=>$admin->tenant_id,'code'=>'TEST-REVIEW','name'=>'Reviewer','data_scope'=>'own']);
        $role->permissions()->sync([Permission::where('code','sales.margin.approve')->value('id')]);
        $user=User::create(['tenant_id'=>$admin->tenant_id,'name'=>'Người thay','email'=>'backup@test.local','password'=>'Admin@123','is_active'=>true]);
        $user->roles()->sync([$role->id]);
        return $user;
    }

    private function draft(User $sales): Quotation
    {
        return app(QuotationService::class)->create($sales, ['customer_id'=>Customer::where('tenant_id',$sales->tenant_id)->firstOrFail()->id,'valid_until'=>now()->addDays(15)->toDateString(),
            'items'=>[['sku_id'=>Sku::where('tenant_id',$sales->tenant_id)->firstOrFail()->id,'quantity'=>1,'unit_price'=>3500000,'vat_rate'=>8]]]);
    }

    private function policy(User $admin, array $extra = []): array
    {
        return $extra + ['approvers'=>[$admin->id],'exception_approvers'=>[],'minimum_margin'=>0,'maximum_discount'=>0,'amount_threshold'=>null,'separation_of_duties'=>true];
    }

    public function test_delegation_is_visible_only_during_validity_and_records_actual_actor(): void
    {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $backup=$this->reviewer($admin);
        $token=$this->token($admin);
        $backupToken=$this->token($backup);
        $quote=$this->draft($sales);
        $this->withToken($token)->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin))->assertOk();
        app(QuotationWorkflow::class)->submit($quote,$sales);
        $this->withToken($backupToken)->getJson('/api/v1/quotations/'.$quote->id)->assertNotFound();
        $rule=['from_user_id'=>$admin->id,'to_user_id'=>$backup->id,'starts_at'=>now()->subMinute()->toIso8601String(),'ends_at'=>now()->addHour()->toIso8601String(),'reason'=>'Nghỉ phép có người thay'];
        $this->withToken($token)->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin,['delegations'=>[$rule]]))->assertOk();
        $this->withToken($backupToken)->getJson('/api/v1/quotations/'.$quote->id)->assertOk()->assertJsonPath('data.can_approve',true);
        $this->withToken($backupToken)->getJson('/api/v1/approval-center')->assertOk()->assertJsonFragment(['code'=>$quote->code]);
        $this->travel(2)->hours();
        $this->withToken($backupToken)->getJson('/api/v1/quotations/'.$quote->id)->assertNotFound();
        $this->withToken($backupToken)->postJson('/api/v1/quotations/'.$quote->id.'/approve',['status'=>'approved'])->assertForbidden();
        $this->assertSame('pending_approval',$quote->fresh()->status);
        $this->travelBack();
        $this->withToken($backupToken)->postJson('/api/v1/quotations/'.$quote->id.'/approve',['status'=>'approved'])->assertOk()->assertJsonPath('data.approval_steps.0.decided_by',$backup->id)->assertJsonPath('data.approval_steps.0.delegated',true);
        $this->withToken($backupToken)->postJson('/api/v1/quotations/'.$quote->id.'/approve',['status'=>'approved'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs',['entity_type'=>'quotation','entity_id'=>$quote->id,'user_id'=>$backup->id,'action'=>'approved_quotation']);
    }

    public function test_self_approval_policy_is_frozen_and_cannot_be_bypassed_using_delegation(): void
    {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $backup=$this->reviewer($admin);
        $token=$this->token($admin);
        $backupToken=$this->token($backup);
        $quote=$this->draft($admin);
        $this->withToken($token)->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin))->assertOk();
        $this->withToken($token)->postJson('/api/v1/quotations/'.$quote->id.'/workflow/submit')->assertUnprocessable();
        $this->assertSame('draft',$quote->fresh()->status);
        $rule=['from_user_id'=>$admin->id,'to_user_id'=>$backup->id,'starts_at'=>now()->subMinute()->toIso8601String(),'ends_at'=>now()->addHour()->toIso8601String(),'reason'=>'Người độc lập kiểm tra'];
        $this->withToken($token)->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin,['delegations'=>[$rule]]))->assertOk();
        $this->withToken($token)->postJson('/api/v1/quotations/'.$quote->id.'/workflow/submit')->assertOk();
        $this->withToken($token)->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin,['separation_of_duties'=>false]))->assertOk();
        $this->withToken($token)->postJson('/api/v1/quotations/'.$quote->id.'/approve',['status'=>'approved'])->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/quotations/'.$quote->id)->assertJsonPath('data.can_approve',false);
        $this->withToken($backupToken)->postJson('/api/v1/quotations/'.$quote->id.'/approve',['status'=>'rejected','reason'=>'Cần sửa nội dung báo giá'])->assertOk()->assertJsonPath('data.status','rejected');
    }

    public function test_invalid_delegations_and_unprivileged_configuration_are_rejected(): void
    {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $backup=$this->reviewer($admin);
        $token=$this->token($admin);
        $rule=['from_user_id'=>$admin->id,'to_user_id'=>$backup->id,'starts_at'=>now()->toIso8601String(),'ends_at'=>now()->addHour()->toIso8601String(),'reason'=>'Nghỉ phép có người thay'];
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $this->withToken($token)->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin,['delegations'=>[array_replace($rule,['to_user_id'=>$sales->id])]]))->assertUnprocessable();
        $other=\App\Models\Tenant::create(['code'=>'OTHER','name'=>'Công ty khác','status'=>'active']);
        $foreign=User::create(['tenant_id'=>$other->id,'name'=>'Ngoài công ty','email'=>'foreign@test.local','password'=>'Admin@123','is_active'=>true]);
        $this->withToken($token)->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin,['delegations'=>[array_replace($rule,['to_user_id'=>$foreign->id])]]))->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin,['delegations'=>[$rule,$rule]]))->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin,['delegations'=>[array_replace($rule,['to_user_id'=>$admin->id])]]))->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin,['delegations'=>[array_replace($rule,['ends_at'=>now()->subHour()->toIso8601String()])]]))->assertUnprocessable();
        $backup->roles()->first()->permissions()->syncWithoutDetaching([Permission::where('code','user.manage')->value('id')]);
        $this->withToken($this->token($backup))->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin,['delegations'=>[$rule]]))->assertForbidden();
    }

    public function test_backup_cannot_skip_a_level_and_losing_permission_revokes_delegation(): void
    {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $director=User::where('email','director@vk-kpi.local')->firstOrFail();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $backup=$this->reviewer($admin);
        $token=$this->token($admin);
        $backupToken=$this->token($backup);
        $rule=['from_user_id'=>$director->id,'to_user_id'=>$backup->id,'starts_at'=>now()->subMinute()->toIso8601String(),'ends_at'=>now()->addHour()->toIso8601String(),'reason'=>'Duyệt thay cấp cuối'];
        $this->withToken($token)->postJson('/api/v1/tenant/sales-quotation-approval',$this->policy($admin,['approvers'=>[$admin->id,$director->id],'delegations'=>[$rule]]))->assertOk();
        $quote=app(QuotationWorkflow::class)->submit($this->draft($sales),$sales);
        $this->withToken($backupToken)->postJson('/api/v1/quotations/'.$quote->id.'/approve',['status'=>'approved'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/quotations/'.$quote->id.'/approve',['status'=>'approved'])->assertOk()->assertJsonPath('data.status','pending_approval');
        $this->withToken($backupToken)->getJson('/api/v1/quotations/'.$quote->id)->assertOk()->assertJsonPath('data.can_approve',true);
        $backup->roles()->first()->permissions()->detach();
        $this->withToken($backupToken)->postJson('/api/v1/quotations/'.$quote->id.'/approve',['status'=>'approved'])->assertForbidden();
        $this->assertSame('pending_approval',$quote->fresh()->status);
    }
}
