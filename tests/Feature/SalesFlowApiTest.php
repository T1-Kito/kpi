<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesFlowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_quick_create_customer_for_quotation_without_duplicate_phone(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');

        $created = $this->withToken($token)
            ->postJson('/api/v1/customers/quick', [
                'name' => 'Khách mua ngoài',
                'tax_code' => '0312345678',
                'contact_name' => 'Anh Nam',
                'phone' => '0909 888 777',
                'email' => 'nam@example.com',
                'billing_address' => 'Tầng 3, tòa nhà ABC, Quận 1',
                'address' => 'Quận 1, TP.HCM',
            ])
            ->assertCreated()
            ->assertJsonPath('meta.duplicate_phone', false)
            ->assertJsonPath('data.tax_code', '0312345678')
            ->assertJsonPath('data.phone', '0909888777');

        $customerId = $created->json('data.id');
        $this->assertMatchesRegularExpression('/^CUS-\d{5}$/', $created->json('data.code'));

        $this->withToken($token)
            ->getJson('/api/v1/customers/tax-lookup/0312345678')
            ->assertOk()
            ->assertJsonPath('data.customer_id', $customerId)
            ->assertJsonPath('data.name', 'Khách mua ngoài')
            ->assertJsonPath('data.billing_address', 'Tầng 3, tòa nhà ABC, Quận 1')
            ->assertJsonPath('data.address', 'Quận 1, TP.HCM')
            ->assertJsonPath('meta.source', 'customer_database');

        $this->withToken($token)
            ->postJson('/api/v1/customers/quick', [
                'name' => 'Tên nhập lại',
                'phone' => '0909888777',
            ])
            ->assertOk()
            ->assertJsonPath('meta.duplicate_phone', true)
            ->assertJsonPath('data.id', $customerId);

        $this->assertSame(1, Customer::where('phone', '0909888777')->count());
    }

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

    public function test_pending_quotation_can_be_updated_before_sales_order(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();

        $created = $this->withToken($token)
            ->postJson('/api/v1/quotations', [
                'customer_id' => 1,
                'items' => [
                    ['sku_id' => $sku->id, 'quantity' => 1, 'unit_price' => 2700000, 'vat_rate' => 0],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval');

        $quotationId = $created->json('data.id');

        $this->withToken($token)
            ->putJson("/api/v1/quotations/{$quotationId}", [
                'customer_id' => 1,
                'items' => [
                    ['sku_id' => $sku->id, 'quantity' => 2, 'unit_price' => 3500000, 'vat_rate' => 8],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'subtotal_amount' => 7000000,
            'tax_amount' => 560000,
            'total_amount' => 7560000,
            'status' => 'ready',
        ]);
        $this->assertSame(1, Quotation::findOrFail($quotationId)->items()->count());
    }

    public function test_quotation_cannot_be_updated_after_sales_order_created(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();

        $quotationId = $this->withToken($token)
            ->postJson('/api/v1/quotations', [
                'customer_id' => 1,
                'items' => [
                    ['sku_id' => $sku->id, 'quantity' => 1, 'unit_price' => 3500000, 'vat_rate' => 8],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)
            ->postJson('/api/v1/sales-orders', ['quotation_id' => $quotationId])
            ->assertCreated();

        $this->withToken($token)
            ->putJson("/api/v1/quotations/{$quotationId}", [
                'customer_id' => 1,
                'items' => [
                    ['sku_id' => $sku->id, 'quantity' => 2, 'unit_price' => 3500000, 'vat_rate' => 8],
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_can_create_multiple_quotations_but_not_duplicate_sales_order_from_same_quotation(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();

        $payload = [
            'customer_id' => 1,
            'items' => [
                [
                    'sku_id' => $sku->id,
                    'quantity' => 2,
                    'unit_price' => 3500000,
                    'vat_rate' => 8,
                ],
            ],
        ];

        $first = $this->withToken($token)
            ->postJson('/api/v1/quotations', $payload)
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/quotations', $payload)
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/sales-orders', ['quotation_id' => $first->json('data.id')])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/sales-orders', ['quotation_id' => $first->json('data.id')])
            ->assertUnprocessable();
    }

    public function test_can_duplicate_quotation_without_copying_sales_order(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $source = Quotation::where('code', 'QUO-DEMO-001')->with('items')->firstOrFail();

        $response = $this->withToken($token)
            ->postJson("/api/v1/quotations/{$source->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.customer_id', $source->customer_id)
            ->assertJsonPath('data.duplicated_from_id', $source->id);

        $copyId = $response->json('data.id');
        $copy = Quotation::with('items')->findOrFail($copyId);

        $this->assertNotSame($source->code, $copy->code);
        $this->assertSame($source->id, $copy->duplicated_from_id);
        $this->assertEquals((float) $source->total_amount, (float) $copy->total_amount);
        $this->assertSame($source->items->count(), $copy->items->count());
        $this->assertDatabaseMissing('sales_orders', ['quotation_id' => $copyId]);
        $this->assertDatabaseHas('quotation_items', [
            'quotation_id' => $copyId,
            'sku_id' => $source->items->first()->sku_id,
            'quantity' => $source->items->first()->quantity,
            'unit_price' => $source->items->first()->unit_price,
            'vat_rate' => $source->items->first()->vat_rate,
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
        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();
        $quotationId = $this->withToken($token)
            ->postJson('/api/v1/quotations', [
                'customer_id' => 1,
                'items' => [
                    ['sku_id' => $sku->id, 'quantity' => 1, 'unit_price' => 3500000],
                ],
            ])
            ->assertCreated()
            ->json('data.id');
        $quotation = Quotation::findOrFail($quotationId);

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

    public function test_receivables_summary_and_sales_order_credit_warning(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $order = SalesOrder::where('code', 'SO-DEMO-001')->firstOrFail();
        $customer = $order->customer()->firstOrFail();
        $customer->update(['credit_limit' => 100000]);

        SalesInvoice::create([
            'tenant_id' => $order->tenant_id,
            'code' => 'INV-TEST-DEBT',
            'sales_order_id' => $order->id,
            'invoice_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'subtotal_amount' => 200000,
            'tax_amount' => 0,
            'total_amount' => 200000,
            'paid_amount' => 0,
            'balance_amount' => 200000,
            'issued_by' => null,
            'status' => 'issued',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/customer-receivables?page_size=100')
            ->assertOk()
            ->assertJsonFragment([
                'code' => $customer->code,
                'status' => 'over_limit',
            ]);

        $this->withToken($token)
            ->postJson("/api/v1/sales-orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('meta.warnings.0.type', 'customer_credit_over_limit');

        $this->assertDatabaseHas('alerts', [
            'alert_type' => 'customer_credit_over_limit',
            'source_type' => 'SalesOrder',
            'source_id' => $order->id,
            'status' => 'open',
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
