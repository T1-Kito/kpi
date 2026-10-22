<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_center_is_public_shell_and_api_returns_scoped_business_documents(): void
    {
        $this->seed();

        $this->get('/approvals')
            ->assertOk()
            ->assertSee('Duyệt tập trung')
            ->assertDontSee('QUO-DEMO-LOW')
            ->assertDontSee('PR-DEMO-APPROVAL')
            ->assertDontSee('PO-DEMO-APPROVAL');

        $token = $this->loginAs('admin@vk-kpi.local');
        $this->withToken($token)->getJson('/api/v1/approval-center')
            ->assertOk()
            ->assertJsonFragment(['code' => 'PR-DEMO-APPROVAL'])
            ->assertJsonFragment(['code' => 'PO-DEMO-APPROVAL']);
    }

    public function test_admin_can_reject_purchase_request_and_purchase_order(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $pr = PurchaseRequest::where('code', 'PR-DEMO-APPROVAL')->firstOrFail();
        $po = PurchaseOrder::where('code', 'PO-DEMO-APPROVAL')->firstOrFail();

        $this->withToken($token)
            ->postJson("/api/v1/purchase-requests/{$pr->id}/reject", ['reason' => 'Nhu cầu chưa đủ căn cứ.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->withToken($token)
            ->postJson("/api/v1/purchase-orders/{$po->id}/reject", ['reason' => 'Cần thương lượng lại giá.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'purchase_request', 'entity_id' => $pr->id, 'action' => 'reject_purchase_request']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'purchase_order', 'entity_id' => $po->id, 'action' => 'reject_purchase_order']);
    }

    public function test_purchase_order_queue_and_actions_follow_current_approval_level(): void
    {
        $this->seed();
        $admin = \App\Models\User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $buyer = \App\Models\User::where('email', 'procurement@vk-kpi.local')->firstOrFail();
        $token = $this->loginAs($admin->email);
        $po = PurchaseOrder::where('code', 'PO-DEMO-APPROVAL')->firstOrFail();
        $flow = [
            ['user_id' => $buyer->id, 'name' => $buyer->name, 'final' => false, 'decided_at' => null],
            ['user_id' => $admin->id, 'name' => $admin->name, 'final' => true, 'decided_at' => null],
        ];
        $po->update(['approval_flow' => $flow]);
        $this->withToken($token)->getJson('/api/v1/approval-center?type=purchase_order')->assertOk()->assertJsonMissing(['code' => $po->code]);
        $this->withToken($token)->getJson('/api/v1/purchase-orders/'.$po->id)->assertOk()->assertJsonPath('data.can_approve', false);
        $this->withToken($token)->postJson('/api/v1/purchase-orders/'.$po->id.'/reject', ['reason' => 'Too early'])->assertForbidden();
        $this->assertSame('draft', $po->fresh()->status);
        $flow[0]['decided_at'] = now()->toIso8601String();
        $po->update(['approval_flow' => $flow]);
        $this->withToken($token)->getJson('/api/v1/approval-center?type=purchase_order')->assertOk()->assertJsonFragment(['code' => $po->code]);
        $this->withToken($token)->getJson('/api/v1/purchase-orders/'.$po->id)->assertOk()->assertJsonPath('data.can_approve', true);
        $this->withToken($token)->postJson('/api/v1/purchase-orders/'.$po->id.'/reject', ['reason' => 'Need changes'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_kpi_queue_respects_review_scope_even_with_unrelated_company_role(): void
    {
        $this->seed();
        \App\Models\KpiAdjustment::query()->delete();
        $reviewer = \App\Models\User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $admin = \App\Models\User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $own = \App\Models\Role::create(['tenant_id'=>$reviewer->tenant_id,'code'=>'REVIEW-OWN','name'=>'Own review','data_scope'=>'own']);
        $own->permissions()->attach(\App\Models\Permission::where('code','kpi.adjustment.review')->value('id'));
        $company = \App\Models\Role::create(['tenant_id'=>$reviewer->tenant_id,'code'=>'UNRELATED-COMPANY','name'=>'Unrelated company role','data_scope'=>'company']);
        $company->permissions()->attach(\App\Models\Permission::where('code','master.cost.view')->value('id'));
        $reviewer->roles()->sync([$own->id,$company->id]);
        foreach (['pending','approved'] as $status) {
            foreach ([$reviewer,$admin] as $employee) {
                \App\Models\KpiAdjustment::create(['tenant_id'=>$admin->tenant_id,'user_id'=>$employee->id,'created_by'=>$admin->id,'adjustment_type'=>'bonus','points'=>2,'period_type'=>'month','period_start'=>'2026-10-01','period_end'=>'2026-10-31','reason'=>'Scope fixture','status'=>$status]);
            }
        }
        $response = $this->withToken($this->loginAs($reviewer->email))->getJson('/api/v1/approval-center')->assertOk()
            ->assertJsonCount(1,'data.pending')->assertJsonCount(1,'data.recent');
        $this->assertSame($reviewer->name,$response->json('data.pending.0.owner'));
        $this->assertSame($reviewer->name,$response->json('data.recent.0.owner'));
        $foreign = \App\Models\KpiAdjustment::where('user_id',$admin->id)->where('status','pending')->firstOrFail();
        $this->postJson('/api/v1/kpi/adjustments/'.$foreign->id.'/review',['status'=>'approved'])->assertNotFound();
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
