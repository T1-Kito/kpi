<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\Alert;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesFlowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_assigned_lead_creates_follow_up_task(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $salesUserId = $this->getJsonWithToken($token, '/api/v1/me')['data']['id'];

        $response = $this->withToken($token)
            ->postJson('/api/v1/leads', [
                'name' => 'Lead mới',
                'phone' => '0903999999',
                'source' => 'Website',
                'assigned_to' => $salesUserId,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('meta.duplicate_phone_warning', false);

        $this->assertDatabaseHas('tasks', [
            'source_type' => 'Lead',
            'assignee_id' => $salesUserId,
            'task_type' => 'lead_follow_up',
        ]);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $salesUserId,
            'source_type' => 'Task',
            'status' => 'unread',
        ]);
        $this->assertDatabaseHas('business_events', ['event_type' => 'LeadAssigned']);
    }

    public function test_low_margin_quotation_creates_pending_approval(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();

        $response = $this->withToken($token)
            ->postJson('/api/v1/quotations', [
                'customer_id' => 1,
                'items' => [
                    ['sku_id' => $sku->id, 'quantity' => 1, 'unit_price' => 2700000],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval');

        $quotationId = $response->json('data.id');
        $this->assertDatabaseHas('approvals', [
            'source_type' => 'Quotation',
            'source_id' => $quotationId,
            'status' => 'pending',
        ]);
    }

    public function test_quotation_calculates_vat_per_item(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();

        $response = $this->withToken($token)
            ->postJson('/api/v1/quotations', [
                'customer_id' => 1,
                'items' => [
                    [
                        'sku_id' => $sku->id,
                        'quantity' => 2,
                        'unit_price' => 3500000,
                        'vat_rate' => 8,
                    ],
                ],
            ])
            ->assertCreated();

        $quotationId = $response->json('data.id');
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'subtotal_amount' => 7000000,
            'tax_amount' => 560000,
            'total_amount' => 7560000,
        ]);
        $this->assertDatabaseHas('quotation_items', [
            'quotation_id' => $quotationId,
            'vat_rate' => 8,
            'vat_amount' => 560000,
            'line_total' => 7560000,
        ]);
    }

    public function test_confirming_sales_order_reserves_available_stock(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $order = SalesOrder::where('code', 'SO-DEMO-001')->firstOrFail();
        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();

        $before = InventoryBalance::where('sku_id', $sku->id)->firstOrFail();
        $availableBefore = (float) $before->available;
        $reservedBefore = (float) $before->reserved;

        $this->withToken($token)
            ->postJson("/api/v1/sales-orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.stock_status', 'reserved')
            ->assertJsonPath('data.status', 'confirmed');

        $after = $before->refresh();
        $this->assertEquals($availableBefore - 1, (float) $after->available);
        $this->assertEquals($reservedBefore + 1, (float) $after->reserved);
        $this->assertDatabaseHas('tasks', [
            'source_type' => 'SalesOrder',
            'source_id' => $order->id,
            'task_type' => 'warehouse_issue',
        ]);
    }

    public function test_confirming_sales_order_with_shortage_creates_alert(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $quotation = Quotation::where('code', 'QUO-DEMO-001')->firstOrFail();

        $quotation->items()->firstOrFail()->update([
            'quantity' => 999,
            'line_total' => 999 * 3500000,
            'line_cost' => 999 * 2600000,
        ]);

        $create = $this->withToken($token)
            ->postJson('/api/v1/sales-orders', ['quotation_id' => $quotation->id])
            ->assertCreated();

        $orderId = $create->json('data.id');

        $this->withToken($token)
            ->postJson("/api/v1/sales-orders/{$orderId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.stock_status', 'shortage');

        $this->assertDatabaseHas('alerts', [
            'alert_type' => 'stock_shortage',
            'source_type' => 'SalesOrder',
            'source_id' => $orderId,
            'status' => 'open',
        ]);
        $alert = Alert::where('source_type', 'SalesOrder')->where('source_id', $orderId)->firstOrFail();
        $this->assertDatabaseHas('notifications', [
            'source_type' => 'Alert',
            'source_id' => $alert->id,
            'status' => 'unread',
        ]);
        $this->assertDatabaseHas('purchase_requests', [
            'source_type' => 'SalesOrder',
            'source_id' => $orderId,
            'status' => 'draft',
        ]);

        $request = PurchaseRequest::where('source_id', $orderId)->firstOrFail();
        $this->assertDatabaseHas('tasks', [
            'task_type' => 'purchase_request',
            'source_type' => 'PurchaseRequest',
            'source_id' => $request->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function getJsonWithToken(string $token, string $uri): array
    {
        return $this->withToken($token)->getJson($uri)->json();
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
