<?php

namespace Tests\Feature;

use App\Models\GoodsIssue;
use App\Models\InventoryBalance;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Sku;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementInventoryFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_shortage_can_flow_from_purchase_request_to_purchase_order_and_goods_receipt(): void
    {
        $this->seed();
        $salesToken = $this->loginAs('sales@vk-kpi.local');
        $procurementToken = $this->loginAs('procurement@vk-kpi.local');
        $warehouseToken = $this->loginAs('warehouse@vk-kpi.local');

        $quotation = Quotation::where('code', 'QUO-DEMO-001')->firstOrFail();
        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();
        $warehouse = Warehouse::where('code', 'WH-HCM')->firstOrFail();
        $quotation->items()->firstOrFail()->update([
            'quantity' => 999,
            'line_total' => 999 * 3500000,
            'line_cost' => 999 * 2600000,
        ]);

        $salesOrderId = $this->withToken($salesToken)
            ->postJson('/api/v1/sales-orders', ['quotation_id' => $quotation->id])
            ->assertCreated()
            ->json('data.id');

        $this->withToken($salesToken)
            ->postJson("/api/v1/sales-orders/{$salesOrderId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.stock_status', 'shortage');

        $purchaseRequest = PurchaseRequest::where('source_type', 'SalesOrder')
            ->where('source_id', $salesOrderId)
            ->firstOrFail();

        $this->withToken($procurementToken)
            ->postJson("/api/v1/purchase-requests/{$purchaseRequest->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $purchaseOrderId = $this->withToken($procurementToken)
            ->postJson('/api/v1/purchase-orders', [
                'purchase_request_id' => $purchaseRequest->id,
                'supplier_id' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->withToken($procurementToken)
            ->postJson("/api/v1/purchase-orders/{$purchaseOrderId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $receiptId = $this->withToken($warehouseToken)
            ->postJson('/api/v1/goods-receipts', [
                'purchase_order_id' => $purchaseOrderId,
                'warehouse_id' => $warehouse->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->withToken($warehouseToken)
            ->postJson("/api/v1/goods-receipts/{$receiptId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('purchase_orders', ['id' => $purchaseOrderId, 'status' => 'received']);
        $this->assertDatabaseHas('sales_orders', ['id' => $salesOrderId, 'stock_status' => 'reserved', 'status' => 'confirmed']);
        $this->assertDatabaseHas('alerts', [
            'alert_type' => 'stock_shortage',
            'source_type' => 'SalesOrder',
            'source_id' => $salesOrderId,
            'status' => 'resolved',
        ]);
        $this->assertDatabaseHas('tasks', [
            'task_type' => 'warehouse_issue',
            'source_type' => 'SalesOrder',
            'source_id' => $salesOrderId,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'sku_id' => $sku->id,
            'transaction_type' => 'receipt',
            'source_type' => 'GoodsReceipt',
            'source_id' => $receiptId,
        ]);

        $issueId = $this->withToken($warehouseToken)
            ->postJson('/api/v1/goods-issues', [
                'sales_order_id' => $salesOrderId,
                'warehouse_id' => $warehouse->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->withToken($warehouseToken)
            ->postJson("/api/v1/goods-issues/{$issueId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('sales_orders', ['id' => $salesOrderId, 'status' => 'completed']);
        $this->assertDatabaseHas('inventory_transactions', [
            'sku_id' => $sku->id,
            'transaction_type' => 'issue',
            'source_type' => 'GoodsIssue',
            'source_id' => $issueId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'sales_order',
            'entity_id' => $salesOrderId,
            'action' => 'confirm_sales_order',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'purchase_request',
            'entity_id' => $purchaseRequest->id,
            'action' => 'approve_purchase_request',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'purchase_order',
            'entity_id' => $purchaseOrderId,
            'action' => 'mark_purchase_order_received',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'goods_receipt',
            'entity_id' => $receiptId,
            'action' => 'confirm_goods_receipt',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'goods_issue',
            'entity_id' => $issueId,
            'action' => 'confirm_goods_issue',
        ]);

        $this->assertContains('purchaseRequest', collect($this->withToken($salesToken)
            ->getJson("/api/v1/sales-orders/{$salesOrderId}")
            ->assertOk()
            ->json('data.related_documents'))->pluck('type')->all());
        $this->assertContains('purchaseOrder', collect($this->withToken($procurementToken)
            ->getJson("/api/v1/purchase-requests/{$purchaseRequest->id}")
            ->assertOk()
            ->json('data.related_documents'))->pluck('type')->all());
        $this->assertContains('goodsReceipt', collect($this->withToken($procurementToken)
            ->getJson("/api/v1/purchase-orders/{$purchaseOrderId}")
            ->assertOk()
            ->json('data.related_documents'))->pluck('type')->all());
        $this->assertContains('salesOrder', collect($this->withToken($warehouseToken)
            ->getJson("/api/v1/goods-receipts/{$receiptId}")
            ->assertOk()
            ->json('data.related_documents'))->pluck('type')->all());
        $this->assertContains('salesOrder', collect($this->withToken($warehouseToken)
            ->getJson("/api/v1/goods-issues/{$issueId}")
            ->assertOk()
            ->json('data.related_documents'))->pluck('type')->all());
    }

    public function test_reserved_sales_order_can_be_issued_from_inventory(): void
    {
        $this->seed();
        $salesToken = $this->loginAs('sales@vk-kpi.local');
        $warehouseToken = $this->loginAs('warehouse@vk-kpi.local');
        $order = SalesOrder::where('code', 'SO-DEMO-001')->firstOrFail();
        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();
        $warehouse = Warehouse::where('code', 'WH-HCM')->firstOrFail();
        $balance = InventoryBalance::where('sku_id', $sku->id)->firstOrFail();

        $this->withToken($salesToken)
            ->postJson("/api/v1/sales-orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.stock_status', 'reserved');

        $issueId = $this->withToken($warehouseToken)
            ->postJson('/api/v1/goods-issues', [
                'sales_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->withToken($warehouseToken)
            ->postJson("/api/v1/goods-issues/{$issueId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $after = $balance->refresh();
        $this->assertEquals(11.0, (float) $after->on_hand);
        $this->assertEquals(0.0, (float) $after->reserved);
        $this->assertEquals(11.0, (float) $after->available);
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'status' => 'completed']);
        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'issue',
            'source_type' => 'GoodsIssue',
            'source_id' => $issueId,
        ]);
        $this->assertNotNull(GoodsIssue::find($issueId)?->confirmed_at);
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
