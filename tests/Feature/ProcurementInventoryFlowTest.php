<?php

namespace Tests\Feature;

use App\Models\GoodsIssue;
use App\Models\CustomerPayment;
use App\Models\Delivery;
use App\Models\InventoryBalance;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\SalesInvoice;
use App\Models\Sku;
use App\Models\SupplierQuotation;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementInventoryFlowTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CompletesSalesQuotation;

    public function test_supplier_quotation_page_renders_approved_request_queue(): void
    {
        $this->seed();
        $this->get('/supplier-quotations')->assertOk()->assertSee('Yêu cầu chờ xử lý báo giá')->assertSee('supplierQuotationsRoot')
            ->assertSee('data-sq-filter="request"', false)->assertSee('data-sq-filter="supplier"', false)->assertSee('data-sq-filter="status"', false);
    }

    public function test_quick_product_creation_checks_duplicate_and_permission(): void
    {
        $this->seed();
        $admin = $this->loginAs('admin@vk-kpi.local');
        $payload = ['name' => 'Mặt hàng mua mới', 'unit' => 'cái', 'quick_create' => true];
        $this->withToken($admin)->postJson('/api/v1/skus', $payload)->assertCreated()->assertJsonPath('data.name', $payload['name']);
        $this->withToken($admin)->postJson('/api/v1/skus', $payload)->assertStatus(422);
        $user = \App\Models\User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        foreach ($user->roles as $role) $role->permissions()->detach();
        $sales = $this->loginAs('sales@vk-kpi.local');
        $payload['name'] = 'Không được phép tạo';
        $this->withToken($sales)->postJson('/api/v1/skus', $payload)->assertForbidden();
        $this->assertDatabaseMissing('skus', ['name' => $payload['name']]);
    }

    public function test_supplier_quote_requires_approved_request_and_respects_quantity(): void
    {
        $this->seed();
        $token = $this->loginAs('procurement@vk-kpi.local');
        $sku = Sku::firstOrFail();
        $id = $this->withToken($token)->postJson('/api/v1/purchase-requests', [
            'reason' => 'Kiểm tra luồng báo giá', 'items' => [['sku_id' => $sku->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.id');
        $payload = ['purchase_request_id' => $id, 'supplier_id' => 1, 'quoted_at' => now()->toDateString(),
            'lines' => [['sku_id' => $sku->id, 'quantity' => 2, 'unit_price' => 10000]]];
        $this->withToken($token)->postJson('/api/v1/supplier-quotations', $payload)->assertStatus(422);
        $this->withToken($token)->postJson("/api/v1/purchase-requests/{$id}/approve")->assertOk();
        $payload['lines'][0]['quantity'] = 3;
        $this->withToken($token)->postJson('/api/v1/supplier-quotations', $payload)->assertStatus(422);
        $payload['lines'][0]['quantity'] = 2;
        $quoteId = $this->withToken($token)->postJson('/api/v1/supplier-quotations', $payload)->assertCreated()->json('data.id');
        $orderId = $this->withToken($token)->postJson("/api/v1/supplier-quotations/{$quoteId}/select", ['create_purchase_order' => true])
            ->assertOk()->assertJsonPath('purchase_order.status', 'draft')->json('purchase_order.id');
        $this->withToken($token)->postJson("/api/v1/supplier-quotations/{$quoteId}/select", ['create_purchase_order' => true])
            ->assertOk()->assertJsonPath('purchase_order.id', $orderId);
        $this->assertSame(1, \App\Models\PurchaseOrder::where('supplier_quotation_id', $quoteId)->count());
        $adminToken = $this->loginAs('admin@vk-kpi.local');
        $buyer = \App\Models\User::where('email', 'procurement@vk-kpi.local')->firstOrFail();
        $director = \App\Models\User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $this->withToken($token)->postJson("/api/v1/purchase-orders/{$orderId}/approval-flow", ['approvers' => [$buyer->id, $director->id]])->assertForbidden();
        $this->withToken($adminToken)->postJson("/api/v1/purchase-orders/{$orderId}/approval-flow", ['approvers' => [$buyer->id, $director->id]])->assertOk();
        $this->withToken($adminToken)->postJson("/api/v1/purchase-orders/{$orderId}/approve")->assertStatus(422);
        $this->withToken($token)->postJson("/api/v1/purchase-orders/{$orderId}/approve")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->withToken($token)->postJson("/api/v1/purchase-orders/{$orderId}/approve")->assertStatus(422);
        $this->withToken($adminToken)->postJson("/api/v1/purchase-orders/{$orderId}/approval-flow", ['approvers' => [$buyer->id, $director->id]])->assertStatus(422);
        $this->withToken($adminToken)->postJson("/api/v1/purchase-orders/{$orderId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->withToken($token)->postJson('/api/v1/purchase-orders', ['supplier_quotation_id' => $quoteId])->assertStatus(422);
    }

    public function test_supplier_quote_can_save_original_document_with_creation(): void
    {
        $this->seed();
        \Illuminate\Support\Facades\Storage::fake('local');
        $token = $this->loginAs('procurement@vk-kpi.local');
        $response = $this->withToken($token)->post('/api/v1/supplier-quotations', [
            'supplier_id' => 1,
            'quoted_at' => now()->toDateString(),
            'lines' => [['sku_id' => Sku::firstOrFail()->id, 'quantity' => 1, 'unit_price' => 10000]],
            'document' => \Illuminate\Http\UploadedFile::fake()->create('bao-gia-goc.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.document_name', 'bao-gia-goc.pdf')->assertJsonMissingPath('data.document_path');
        $quotation = \App\Models\SupplierQuotation::findOrFail($response->json('data.id'));
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($quotation->document_path);
        $this->withToken($token)->get('/api/v1/supplier-quotations/'.$quotation->id.'/document')->assertOk();
    }

    public function test_supplier_quote_single_approver_creates_approved_order_and_receipt(): void
    {
        $this->seed();
        \Illuminate\Support\Facades\Storage::fake('local');
        $token = $this->loginAs('admin@vk-kpi.local');
        $admin = \App\Models\User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $buyerToken = $this->loginAs('procurement@vk-kpi.local');
        $config = ['enabled' => true, 'rules' => [['minimum_amount' => 0, 'approvers' => [$admin->id]]]];
        $this->withToken($token)->postJson('/api/v1/tenant/procurement-approval', $config)->assertOk();
        $pr = $this->withToken($buyerToken)->postJson('/api/v1/purchase-requests', ['reason' => 'Duyệt một người', 'items' => [['sku_id' => Sku::firstOrFail()->id, 'quantity' => 1]]])->assertCreated()->json('data.id');
        $this->withToken($buyerToken)->postJson("/api/v1/purchase-requests/{$pr}/approve")->assertOk();
        $quote = $this->withToken($token)->post('/api/v1/supplier-quotations', [
            'purchase_request_id' => $pr, 'supplier_id' => 1, 'quoted_at' => now()->toDateString(),
            'lines' => [['sku_id' => Sku::firstOrFail()->id, 'quantity' => 1, 'unit_price' => 10000]],
            'document' => \Illuminate\Http\UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.approval_flow.0.final', true)->json('data.id');
        $this->withToken($buyerToken)->postJson("/api/v1/supplier-quotations/{$quote}/select", ['create_purchase_order' => true])->assertForbidden();
        $order = $this->withToken($token)->postJson("/api/v1/supplier-quotations/{$quote}/select", ['create_purchase_order' => true])
            ->assertOk()->assertJsonPath('purchase_order.status', 'approved')->json('purchase_order.id');
        $this->assertSame(1, \App\Models\GoodsReceipt::where('purchase_order_id', $order)->count());
        $this->withToken($token)->postJson("/api/v1/supplier-quotations/{$quote}/select", ['create_purchase_order' => true])->assertOk();
        $this->assertSame(1, \App\Models\GoodsReceipt::where('purchase_order_id', $order)->count());
    }

    public function test_separate_five_level_order_and_receipt_approvals_do_not_post_stock_early(): void
    {
        $this->seed();
        \Illuminate\Support\Facades\Storage::fake('local');
        $admin = \App\Models\User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $token = $this->loginAs($admin->email);
        $users = [$admin];
        $tokens = [$token];
        for ($index = 2; $index <= 5; $index++) {
            $user = \App\Models\User::create(['tenant_id' => $admin->tenant_id, 'name' => 'Approver '.$index, 'email' => "level{$index}@test.local", 'password' => \Illuminate\Support\Facades\Hash::make('Admin@123'), 'is_active' => true]);
            $user->roles()->sync($admin->roles->pluck('id'));
            $users[] = $user;
            $tokens[] = $this->loginAs($user->email);
        }
        $ids = array_map(fn ($user) => $user->id, $users);
        $buyerToken = $this->loginAs('procurement@vk-kpi.local');
        $this->withToken($buyerToken)->postJson('/api/v1/tenant/operational-approval', ['type' => 'purchase_order', 'approvers' => $ids])->assertForbidden();
        foreach (['purchase_order', 'goods_receipt'] as $type) {
            $this->withToken($token)->postJson('/api/v1/tenant/operational-approval', ['type' => $type, 'approvers' => [$admin->id]])->assertStatus(422);
            $this->withToken($token)->postJson('/api/v1/tenant/operational-approval', ['type' => $type, 'approvers' => $ids])->assertOk();
        }
        $this->withToken($token)->postJson('/api/v1/tenant/procurement-approval', ['enabled' => true, 'rules' => [['minimum_amount' => 0, 'approvers' => $ids]]])->assertOk();
        $sku = Sku::firstOrFail();
        $pr = $this->withToken($buyerToken)->postJson('/api/v1/purchase-requests', ['reason' => 'Five levels', 'items' => [['sku_id' => $sku->id, 'quantity' => 2]]])->assertCreated()->json('data.id');
        $this->withToken($buyerToken)->postJson("/api/v1/purchase-requests/{$pr}/approve")->assertOk();
        $quote = $this->withToken($token)->post('/api/v1/supplier-quotations', ['purchase_request_id' => $pr, 'supplier_id' => 1, 'quoted_at' => now()->toDateString(), 'lines' => [['sku_id' => $sku->id, 'quantity' => 2, 'unit_price' => 10000]], 'document' => \Illuminate\Http\UploadedFile::fake()->create('q.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        foreach (array_slice($tokens, 0, 4) as $actorToken) {
            $this->withToken($actorToken)->postJson("/api/v1/supplier-quotations/{$quote}/select", ['create_purchase_order' => true])->assertOk()->assertJsonPath('purchase_order', null);
        }
        $po = $this->withToken($tokens[4])->postJson("/api/v1/supplier-quotations/{$quote}/select", ['create_purchase_order' => true])->assertOk()->assertJsonPath('purchase_order.status', 'draft')->json('purchase_order.id');
        $this->assertSame(0, \App\Models\GoodsReceipt::where('purchase_order_id', $po)->count());
        $this->withToken($tokens[4])->postJson("/api/v1/purchase-orders/{$po}/approve")->assertStatus(422);
        foreach ($tokens as $index => $actorToken) {
            $this->withToken($actorToken)->postJson("/api/v1/purchase-orders/{$po}/approve")->assertOk()->assertJsonPath('data.status', $index === 4 ? 'approved' : 'draft');
        }
        $receipt = \App\Models\GoodsReceipt::where('purchase_order_id', $po)->firstOrFail();
        $before = InventoryBalance::where('sku_id', $sku->id)->sum('on_hand');
        $this->withToken($tokens[4])->postJson("/api/v1/goods-receipts/{$receipt->id}/confirm")->assertStatus(422);
        foreach ($tokens as $index => $actorToken) {
            $this->withToken($actorToken)->postJson("/api/v1/goods-receipts/{$receipt->id}/confirm")->assertOk()->assertJsonPath('data.status', $index === 4 ? 'confirmed' : 'draft');
            if ($index < 4) {
                $this->assertEquals($before, InventoryBalance::where('sku_id', $sku->id)->sum('on_hand'));
                $this->assertSame('approved', PurchaseOrder::findOrFail($po)->status);
            }
        }
        $this->assertEquals($before + 2, InventoryBalance::where('sku_id', $sku->id)->sum('on_hand'));
        $this->assertSame('received', PurchaseOrder::findOrFail($po)->status);
        $this->withToken($token)->postJson("/api/v1/goods-receipts/{$receipt->id}/confirm")->assertStatus(422);
        $this->assertEquals($before + 2, InventoryBalance::where('sku_id', $sku->id)->sum('on_hand'));
    }

    public function test_supplier_quote_multi_approval_creates_order_only_after_final_approval(): void
    {
        $this->seed();
        $buyerToken = $this->loginAs('procurement@vk-kpi.local');
        $adminToken = $this->loginAs('admin@vk-kpi.local');
        $buyer = \App\Models\User::where('email', 'procurement@vk-kpi.local')->firstOrFail();
        $admin = \App\Models\User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sku = Sku::firstOrFail();
        $pr = $this->withToken($buyerToken)->postJson('/api/v1/purchase-requests', ['reason' => 'Duyệt báo giá nhiều người', 'items' => [['sku_id' => $sku->id, 'quantity' => 1]]])->assertCreated()->json('data.id');
        $this->withToken($buyerToken)->postJson("/api/v1/purchase-requests/{$pr}/approve")->assertOk();
        $config = ['enabled' => true, 'rules' => [['minimum_amount' => 0, 'approvers' => [$buyer->id, $admin->id]]]];
        $this->withToken($buyerToken)->postJson('/api/v1/tenant/procurement-approval', $config)->assertForbidden();
        $this->withToken($adminToken)->postJson('/api/v1/tenant/procurement-approval', $config)->assertOk();
        $quote = $this->withToken($buyerToken)->postJson('/api/v1/supplier-quotations', ['purchase_request_id' => $pr, 'supplier_id' => 1, 'quoted_at' => now()->toDateString(), 'lines' => [['sku_id' => $sku->id, 'quantity' => 1, 'unit_price' => 10000]]])->assertCreated()->json('data.id');
        $this->withToken($buyerToken)->getJson("/api/v1/supplier-quotations/{$quote}")->assertOk()->assertJsonPath('data.approval_flow.0.user_id', $buyer->id)->assertJsonPath('data.approval_flow.1.final', true);
        $this->withToken($adminToken)->postJson("/api/v1/supplier-quotations/{$quote}/approval-flow", ['approvers' => [$buyer->id, $admin->id]])->assertStatus(422);
        $this->withToken($adminToken)->postJson('/api/v1/tenant/procurement-approval', ['enabled' => true, 'rules' => [['minimum_amount' => 0, 'approvers' => [$admin->id, $buyer->id]]]])->assertOk();
        $this->withToken($buyerToken)->getJson("/api/v1/supplier-quotations/{$quote}")->assertOk()->assertJsonPath('data.approval_flow.0.user_id', $admin->id);
        $this->withToken($adminToken)->postJson('/api/v1/tenant/procurement-approval', $config)->assertOk();
        $this->withToken($buyerToken)->postJson("/api/v1/supplier-quotations/{$quote}/select", ['create_purchase_order' => true])->assertStatus(422)->assertJsonPath('message', 'Cần đính kèm file báo giá gốc trước khi duyệt.');
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->withToken($buyerToken)->post("/api/v1/supplier-quotations/{$quote}/document", ['document' => \Illuminate\Http\UploadedFile::fake()->create('bao-gia.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.document_name', 'bao-gia.pdf')->assertJsonMissingPath('data.document_path');
        $this->withToken($buyerToken)->get("/api/v1/supplier-quotations/{$quote}/document")->assertOk();
        $this->withToken($buyerToken)->post("/api/v1/supplier-quotations/{$quote}/document", ['document' => \Illuminate\Http\UploadedFile::fake()->create('invalid.txt', 20, 'text/plain')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->withToken($adminToken)->postJson("/api/v1/supplier-quotations/{$quote}/select", ['create_purchase_order' => true])->assertStatus(422);
        $this->withToken($buyerToken)->postJson("/api/v1/supplier-quotations/{$quote}/select", ['create_purchase_order' => true])->assertOk()->assertJsonPath('purchase_order', null)->assertJsonPath('data.status', 'draft');
        $this->assertSame(0, \App\Models\PurchaseOrder::where('supplier_quotation_id', $quote)->count());
        $this->withToken($adminToken)->postJson('/api/v1/tenant/procurement-approval', ['enabled' => true, 'rules' => [['minimum_amount' => 0, 'approvers' => [$admin->id, $buyer->id]]]])->assertOk();
        $this->withToken($buyerToken)->getJson("/api/v1/supplier-quotations/{$quote}")->assertOk()->assertJsonPath('data.approval_flow.0.user_id', $buyer->id);
        $this->withToken($buyerToken)->postJson("/api/v1/supplier-quotations/{$quote}/select")->assertStatus(422);
        $po = $this->withToken($adminToken)->postJson("/api/v1/supplier-quotations/{$quote}/select", ['create_purchase_order' => true])->assertOk()->assertJsonPath('purchase_order.status', 'approved')->json('purchase_order.id');
        $this->withToken($adminToken)->postJson("/api/v1/supplier-quotations/{$quote}/select", ['create_purchase_order' => true])->assertOk()->assertJsonPath('purchase_order.id', $po);
        $this->assertSame(1, \App\Models\PurchaseOrder::where('supplier_quotation_id', $quote)->count());
    }

    public function test_shortage_can_flow_from_purchase_request_to_purchase_order_and_goods_receipt(): void
    {
        $this->seed();
        $salesToken = $this->loginAs('sales@vk-kpi.local');
        $procurementToken = $this->loginAs('procurement@vk-kpi.local');
        $warehouseToken = $this->loginAs('warehouse@vk-kpi.local');
        $adminToken = $this->loginAs('admin@vk-kpi.local');

        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();
        $warehouse = Warehouse::where('code', 'WH-HCM')->firstOrFail();
        $quotationId = $this->withToken($salesToken)
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

        $this->completeSalesQuotation($quotationId);
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
                'receiving_warehouse_id' => $warehouse->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->withToken($procurementToken)
            ->postJson("/api/v1/purchase-orders/{$purchaseOrderId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $automaticReceipt = \App\Models\GoodsReceipt::where('purchase_order_id', $purchaseOrderId)->sole();
        $this->assertSame($warehouse->id, $automaticReceipt->warehouse_id);
        $this->assertSame('draft', $automaticReceipt->status);
        $this->assertDatabaseHas('tasks', ['source_type' => 'GoodsReceipt', 'source_id' => $automaticReceipt->id, 'task_type' => 'goods_receipt', 'status' => 'new']);

        $receiptId = \App\Models\GoodsReceipt::where('purchase_order_id', $purchaseOrderId)->sole()->id;

        $this->withToken($warehouseToken)
            ->postJson("/api/v1/goods-receipts/{$receiptId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
        $this->assertDatabaseHas('tasks', ['source_type' => 'GoodsReceipt', 'source_id' => $receiptId, 'task_type' => 'goods_receipt', 'status' => 'completed']);

        $transactionsBeforeRetry = \App\Models\InventoryTransaction::where('source_type', 'GoodsReceipt')->where('source_id', $receiptId)->count();
        $this->withToken($warehouseToken)->postJson("/api/v1/goods-receipts/{$receiptId}/confirm")->assertUnprocessable();
        $this->assertSame($transactionsBeforeRetry, \App\Models\InventoryTransaction::where('source_type', 'GoodsReceipt')->where('source_id', $receiptId)->count());
        $this->withToken($warehouseToken)->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $purchaseOrderId, 'warehouse_id' => $warehouse->id])->assertUnprocessable();
        $this->assertSame(1, \App\Models\GoodsReceipt::where('purchase_order_id', $purchaseOrderId)->count());

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

        $autoIssue = \App\Models\GoodsIssue::where('sales_order_id', $salesOrderId)->firstOrFail();
        $this->assertSame('draft', $autoIssue->status);
        $issueId = $autoIssue->id;

        $this->withToken($warehouseToken)
            ->postJson("/api/v1/goods-issues/{$issueId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $issueTransactions = \App\Models\InventoryTransaction::where('source_type', 'GoodsIssue')->where('source_id', $issueId)->count();
        $this->withToken($warehouseToken)->postJson("/api/v1/goods-issues/{$issueId}/confirm")->assertUnprocessable();
        $this->assertSame($issueTransactions, \App\Models\InventoryTransaction::where('source_type', 'GoodsIssue')->where('source_id', $issueId)->count());
        $this->assertDatabaseHas('tasks', ['source_type' => 'SalesOrder', 'source_id' => $salesOrderId, 'task_type' => 'warehouse_issue', 'status' => 'completed']);
        $this->assertSame(1, \App\Models\Task::where('source_type', 'SalesOrder')->where('source_id', $salesOrderId)->where('task_type', 'delivery_confirmation')->count());

        $this->assertDatabaseHas('sales_orders', [
            'id' => $salesOrderId,
            'status' => 'awaiting_delivery',
            'delivery_status' => 'pending',
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'sku_id' => $sku->id,
            'transaction_type' => 'issue',
            'source_type' => 'GoodsIssue',
            'source_id' => $issueId,
        ]);

        $deliveryId = $this->withToken($adminToken)
            ->postJson("/api/v1/sales-orders/{$salesOrderId}/deliveries", [
                'recipient_name' => 'Anh Minh',
                'recipient_phone' => '0901000001',
                'delivery_address' => 'Quận 1, TP.HCM',
                'proof_note' => 'Khách đã nhận đủ hàng.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'delivered')
            ->json('data.id');

        $invoiceId = $this->withToken($adminToken)
            ->postJson("/api/v1/sales-orders/{$salesOrderId}/invoices", [
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'issued')
            ->json('data.id');

        $this->assertDatabaseHas('tasks', ['source_type' => 'SalesOrder', 'source_id' => $salesOrderId, 'task_type' => 'delivery_confirmation', 'status' => 'completed']);
        $this->withToken($adminToken)->postJson("/api/v1/sales-orders/{$salesOrderId}/deliveries", [
            'recipient_name' => 'Anh Minh', 'delivery_address' => 'Quận 1, TP.HCM',
        ])->assertUnprocessable();
        $this->withToken($adminToken)->postJson("/api/v1/sales-orders/{$salesOrderId}/invoices", ['invoice_date' => now()->toDateString()])->assertUnprocessable();
        $this->assertSame(1, Delivery::where('sales_order_id', $salesOrderId)->count());
        $this->assertSame(1, SalesInvoice::where('sales_order_id', $salesOrderId)->count());

        $invoice = SalesInvoice::findOrFail($invoiceId);
        $paymentId = $this->withToken($adminToken)
            ->postJson("/api/v1/sales-invoices/{$invoiceId}/payments", [
                'amount' => (float) $invoice->balance_amount,
                'payment_method' => 'bank_transfer',
                'reference_no' => 'BANK-DEMO-001',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotNull(Delivery::find($deliveryId));
        $this->assertNotNull(CustomerPayment::find($paymentId));
        $this->assertDatabaseHas('sales_invoices', ['id' => $invoiceId, 'status' => 'paid', 'balance_amount' => 0]);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $salesOrderId,
            'status' => 'completed',
            'delivery_status' => 'delivered',
            'payment_status' => 'paid',
        ]);

        $this->withToken($adminToken)
            ->getJson('/api/v1/deliveries?page_size=100')
            ->assertOk()
            ->assertJsonFragment(['id' => $salesOrderId, 'delivery_status' => 'delivered']);
        $this->withToken($adminToken)
            ->getJson("/api/v1/deliveries/{$salesOrderId}")
            ->assertOk()
            ->assertJsonPath('data.deliveries.0.id', $deliveryId);
        $this->withToken($adminToken)
            ->getJson('/api/v1/sales-invoices?page_size=100')
            ->assertOk()
            ->assertJsonFragment(['id' => $invoiceId, 'status' => 'paid']);
        $this->withToken($adminToken)
            ->getJson("/api/v1/sales-invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.id', $paymentId);
        $this->withToken($adminToken)
            ->getJson('/api/v1/customer-payments?page_size=100')
            ->assertOk()
            ->assertJsonFragment(['id' => $paymentId, 'reference_no' => 'BANK-DEMO-001']);
        $this->withToken($adminToken)
            ->getJson("/api/v1/customer-payments/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('data.invoice.id', $invoiceId);

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

        $autoIssue = \App\Models\GoodsIssue::where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame('draft', $autoIssue->status);
        $issueId = $autoIssue->id;

        $this->withToken($warehouseToken)
            ->postJson('/api/v1/goods-issues', [
                'sales_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
            ])
            ->assertUnprocessable();

        $this->withToken($warehouseToken)
            ->postJson("/api/v1/goods-issues/{$issueId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $after = $balance->refresh();
        $this->assertEquals(11.0, (float) $after->on_hand);
        $this->assertEquals(0.0, (float) $after->reserved);
        $this->assertEquals(11.0, (float) $after->available);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $order->id,
            'status' => 'awaiting_delivery',
            'delivery_status' => 'pending',
        ]);
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
    public function test_selected_supplier_quotation_can_create_purchase_order_with_source_price(): void
    {
        $this->seed();
        $procurementToken = $this->loginAs('procurement@vk-kpi.local');
        $quotation = SupplierQuotation::where('code', 'SQ-DEMO-001')->firstOrFail();

        $poId = $this->withToken($procurementToken)
            ->postJson('/api/v1/purchase-orders', [
                'supplier_quotation_id' => $quotation->id,
                'expected_delivery_date' => now()->addDays(5)->toDateString(),
                'receiving_warehouse_id' => \App\Models\Warehouse::where('tenant_id', $quotation->tenant_id)->firstOrFail()->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.supplier_quotation_id', $quotation->id)
            ->assertJsonPath('data.total_amount', $quotation->total_amount)
            ->json('data.id');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'supplier_quotation_id' => $quotation->id,
            'supplier_id' => $quotation->supplier_id,
            'total_amount' => $quotation->total_amount,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $poId,
            'unit_price' => 2600000,
            'line_total' => 13000000,
        ]);

        $related = $this->withToken($procurementToken)
            ->getJson("/api/v1/purchase-orders/{$poId}")
            ->assertOk()
            ->json('data.related_documents');

        $this->assertContains('supplierQuotation', collect($related)->pluck('type')->all());
    }
}
