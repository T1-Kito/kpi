<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_center_renders_pending_business_documents(): void
    {
        $this->seed();

        $this->get('/approvals')
            ->assertOk()
            ->assertSee('Duyệt tập trung')
            ->assertSee('QUO-DEMO-LOW')
            ->assertSee('PR-DEMO-APPROVAL')
            ->assertSee('PO-DEMO-APPROVAL');
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

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
