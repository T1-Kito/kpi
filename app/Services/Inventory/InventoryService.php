<?php

namespace App\Services\Inventory;

use App\Models\Alert;
use App\Models\GoodsIssue;
use App\Models\GoodsReceipt;
use App\Models\InventoryBalance;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Task;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessEventPublisher;
use App\Support\CodeGenerator;
use App\Support\TaskService;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly TaskService $tasks,
        private readonly CodeGenerator $codes,
    ) {
    }

    public function createReceiptFromPo(PurchaseOrder $po, User $actor, int $warehouseId, ?int $locationId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($po, $actor, $warehouseId, $locationId) {
            $po = PurchaseOrder::where('tenant_id', $actor->tenant_id)->whereKey($po->id)->lockForUpdate()->firstOrFail()->load('items');
            $existing = GoodsReceipt::where('tenant_id', $po->tenant_id)->where('purchase_order_id', $po->id)->whereIn('status', ['draft', 'confirmed'])->first();
            abort_if($existing, 422, 'Đơn mua đã có phiếu nhập. Vui lòng mở phiếu hiện có để xử lý tiếp.');
            abort_if($po->status !== 'approved', 422, 'Don mua chua duoc duyet.');

            $receipt = GoodsReceipt::create([
                'tenant_id' => $po->tenant_id,
                'code' => $this->nextReceiptCode($po->tenant_id),
                'purchase_order_id' => $po->id,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'status' => 'draft',
                'approval_flow' => app(\App\Support\OperationalApproval::class)->flow($po->tenant_id, 'goods_receipt'),
            ]);

            foreach ($po->items as $item) {
                $receipt->items()->create([
                    'sku_id' => $item->sku_id,
                    'quantity' => $item->quantity,
                    'lot_no' => 'PO-'.$po->code,
                ]);
            }

            $this->audit->record('goods_receipt', $receipt->id, 'create_goods_receipt', $actor, null, $receipt->toArray());
            $this->tasks->create($actor, [
                'module' => 'inventory', 'task_type' => 'goods_receipt', 'priority' => 'high',
                'title' => 'Nhập kho cho đơn mua '.$po->code,
                'description' => 'Phiếu nhập được tạo tự động sau khi đơn mua được duyệt.',
                'source_type' => 'GoodsReceipt', 'source_id' => $receipt->id, 'assignee_id' => null,
            ]);

            return $receipt->load('items.sku:id,sku_code,name');
        });
    }

    public function confirmReceipt(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $actor) {
            $receipt = GoodsReceipt::where('tenant_id', $actor->tenant_id)->whereKey($receipt->id)->lockForUpdate()->firstOrFail()->load('items');
            abort_if($receipt->status === 'confirmed', 422, 'Phieu nhap da duoc xac nhan.');
            abort_unless($receipt->status === 'draft', 422, 'Chỉ được xác nhận phiếu nhập nháp.');
            $sourcePo = PurchaseOrder::where('tenant_id', $actor->tenant_id)->whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail();
            abort_unless($sourcePo->status === 'approved', 422, 'Đơn mua nguồn không còn ở trạng thái đã duyệt.');
            if ($receipt->approval_flow) {
                $flow = app(\App\Support\OperationalApproval::class)->decide($receipt->approval_flow, $actor);
                $receipt->update(['approval_flow' => $flow]);
                $this->audit->record('goods_receipt', $receipt->id, 'approve_goods_receipt_step', $actor, null, ['approval_flow' => $flow]);
                if (collect($flow)->contains(fn ($step) => empty($step['decided_at']))) return $receipt->refresh()->load('items.sku:id,sku_code,name');
            }
            $old = $receipt->only(['status', 'confirmed_by', 'confirmed_at']);

            foreach ($receipt->items as $item) {
                $balance = InventoryBalance::firstOrCreate(
                    [
                        'tenant_id' => $receipt->tenant_id,
                        'warehouse_id' => $receipt->warehouse_id,
                        'warehouse_location_id' => $receipt->warehouse_location_id,
                        'sku_id' => $item->sku_id,
                        'lot_no' => $item->lot_no,
                    ],
                    ['on_hand' => 0, 'reserved' => 0, 'available' => 0],
                );
                $balance = InventoryBalance::whereKey($balance->id)->lockForUpdate()->firstOrFail();
                $balance->update([
                    'on_hand' => $balance->on_hand + $item->quantity,
                    'available' => $balance->available + $item->quantity,
                ]);

                InventoryTransaction::create([
                    'tenant_id' => $receipt->tenant_id,
                    'warehouse_id' => $receipt->warehouse_id,
                    'warehouse_location_id' => $receipt->warehouse_location_id,
                    'sku_id' => $item->sku_id,
                    'lot_no' => $item->lot_no,
                    'transaction_type' => 'receipt',
                    'quantity' => $item->quantity,
                    'source_type' => 'GoodsReceipt',
                    'source_id' => $receipt->id,
                    'created_by' => $actor->id,
                ]);
            }

            $receipt->update(['status' => 'confirmed', 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            foreach (Task::where('tenant_id', $receipt->tenant_id)->where('source_type', 'GoodsReceipt')->where('source_id', $receipt->id)
                ->where('task_type', 'goods_receipt')->whereIn('status', ['new', 'in_progress', 'overdue'])->get() as $task) {
                $this->tasks->changeStatus($task, $actor, 'completed', 'goods_receipt_confirmed');
            }
            $purchaseOrder = PurchaseOrder::where('tenant_id', $receipt->tenant_id)
                ->where('id', $receipt->purchase_order_id)
                ->first();
            $oldPo = $purchaseOrder?->only(['status']);
            $purchaseOrder?->update(['status' => 'received']);

            $this->reserveSourceSalesOrderAfterReceipt($receipt, $actor);

            $this->audit->record('goods_receipt', $receipt->id, 'confirm_goods_receipt', $actor, $old, $receipt->fresh()->only(['status', 'confirmed_by', 'confirmed_at']));
            if ($purchaseOrder) {
                $this->audit->record('purchase_order', $purchaseOrder->id, 'mark_purchase_order_received', $actor, $oldPo, $purchaseOrder->fresh()->only(['status']));
            }
            $this->events->publish($receipt->tenant_id, 'GoodsReceiptConfirmed', 'GoodsReceipt', $receipt->id, ['code' => $receipt->code]);

            return $receipt->refresh()->load('items.sku:id,sku_code,name');
        });
    }

    public function createIssueFromSalesOrder(SalesOrder $order, User $actor, int $warehouseId, ?int $locationId = null, array $documentDetails = []): GoodsIssue
    {
        return DB::transaction(function () use ($order, $actor, $warehouseId, $locationId, $documentDetails) {
            $order = SalesOrder::where('tenant_id', $actor->tenant_id)->whereKey($order->id)->lockForUpdate()->firstOrFail()->load(['items', 'customer', 'quotation']);
            $isDirectDelivery = $order->fulfillment_type === 'supplier_direct';
            abort_if(! $isDirectDelivery && $order->stock_status !== 'reserved', 422, 'Đơn bán chưa giữ hàng.');

            $existingIssue = GoodsIssue::where('tenant_id', $order->tenant_id)
                ->where('sales_order_id', $order->id)
                ->whereIn('status', ['draft', 'confirmed'])
                ->lockForUpdate()
                ->first();
            if ($existingIssue) {
                abort(422, "Đơn bán {$order->code} đã có phiếu xuất {$existingIssue->code}. Vui lòng mở phiếu xuất hiện có để xử lý tiếp.");
            }

            if (! $isDirectDelivery) {
                foreach ($order->items as $item) {
                    $reservedQty = InventoryBalance::where('tenant_id', $order->tenant_id)
                        ->where('warehouse_id', $warehouseId)
                        ->where('sku_id', $item->sku_id)
                        ->sum('reserved');

                    abort_if($reservedQty < $item->quantity, 422, 'Kho chọn chưa đủ hàng đã giữ để xuất.');
                }
            }

            $issue = GoodsIssue::create([
                'tenant_id' => $order->tenant_id,
                'code' => $this->nextIssueCode($order->tenant_id),
                'sales_order_id' => $order->id,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'recipient_name' => $documentDetails['recipient_name'] ?? $order->customer?->contact_name ?? $order->customer?->name,
                'recipient_phone' => $documentDetails['recipient_phone'] ?? $order->customer?->phone,
                'recipient_address' => $documentDetails['recipient_address'] ?? $order->customer?->address ?? $order->customer?->billing_address,
                'delivery_location' => $documentDetails['delivery_location'] ?? $order->customer?->address ?? $order->customer?->billing_address,
                'issue_reason' => $documentDetails['issue_reason'] ?? 'Xuất kho bán hàng cho đơn '.$order->code,
                'source_document' => $documentDetails['source_document'] ?? $order->quotation?->code ?? $order->code,
                'affects_stock' => ! $isDirectDelivery,
                'status' => 'draft',
            ]);

            foreach ($order->items as $item) {
                $issue->items()->create([
                    'sku_id' => $item->sku_id,
                    'quantity' => $item->quantity,
                    'lot_no' => null,
                ]);
            }

            $this->audit->record('goods_issue', $issue->id, 'create_goods_issue', $actor, null, $issue->toArray());

            return $issue->load('items.sku:id,sku_code,name');
        });
    }

    public function ensureDraftIssue(SalesOrder $order, User $actor): ?GoodsIssue
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = SalesOrder::where('tenant_id', $actor->tenant_id)->whereKey($order->id)->lockForUpdate()->firstOrFail()->load('items');
            $existing = GoodsIssue::where('tenant_id', $order->tenant_id)->where('sales_order_id', $order->id)->whereIn('status', ['draft', 'confirmed'])->first();
            if ($existing) return $existing;
            if ($order->stock_status !== 'reserved' && $order->fulfillment_type !== 'supplier_direct') return null;
            $warehouses = \App\Models\Warehouse::where('tenant_id', $order->tenant_id)->where('status', 'active')->orderBy('id')->get();
            if ($order->fulfillment_type === 'supplier_direct') {
                if ($warehouses->count() !== 1) return null;
                return $this->createIssueFromSalesOrder($order, $actor, $warehouses->first()->id);
            }
            $required = $order->items->groupBy('sku_id')->map(fn ($items) => $items->sum('quantity'));
            foreach ($warehouses as $warehouse) {
                $reserved = InventoryBalance::where('tenant_id', $order->tenant_id)->where('warehouse_id', $warehouse->id)->whereIn('sku_id', $required->keys())->get()->groupBy('sku_id')->map(fn ($balances) => $balances->sum('reserved'));
                if ($required->every(fn ($quantity, $skuId) => ($reserved[$skuId] ?? 0) >= $quantity)) {
                    return $this->createIssueFromSalesOrder($order, $actor, $warehouse->id);
                }
            }
            return null;
        });
    }

    public function confirmIssue(GoodsIssue $issue, User $actor): GoodsIssue
    {
        return DB::transaction(function () use ($issue, $actor) {
            $issue = GoodsIssue::where('tenant_id', $actor->tenant_id)->whereKey($issue->id)->lockForUpdate()->firstOrFail()->load('items');
            abort_if($issue->status === 'confirmed', 422, 'Phieu xuat da duoc xac nhan.');
            abort_unless($issue->status === 'draft', 422, 'Chỉ được xác nhận phiếu xuất nháp.');
            $old = $issue->only(['status', 'confirmed_by', 'confirmed_at']);

            if ($issue->affects_stock) foreach ($issue->items as $item) {
                $remaining = (float) $item->quantity;
                $balances = InventoryBalance::where('tenant_id', $issue->tenant_id)
                    ->where('warehouse_id', $issue->warehouse_id)
                    ->where('sku_id', $item->sku_id)
                    ->where('reserved', '>', 0)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($balances as $balance) {
                    if ($remaining <= 0.0001) {
                        break;
                    }

                    $taken = min((float) $balance->reserved, $remaining);
                    $balance->update([
                        'on_hand' => $balance->on_hand - $taken,
                        'reserved' => $balance->reserved - $taken,
                    ]);

                    InventoryTransaction::create([
                        'tenant_id' => $issue->tenant_id,
                        'warehouse_id' => $issue->warehouse_id,
                        'warehouse_location_id' => $balance->warehouse_location_id,
                        'sku_id' => $item->sku_id,
                        'lot_no' => $balance->lot_no,
                        'transaction_type' => 'issue',
                        'quantity' => -1 * abs($taken),
                        'source_type' => 'GoodsIssue',
                        'source_id' => $issue->id,
                        'created_by' => $actor->id,
                    ]);

                    $remaining -= $taken;
                }

                abort_if($remaining > 0.0001, 422, 'Khong du hang da giu de xuat.');
            }

            $issue->update(['status' => 'confirmed', 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            $salesOrder = SalesOrder::where('tenant_id', $issue->tenant_id)
                ->where('id', $issue->sales_order_id)
                ->first();
            $oldOrder = $salesOrder?->only(['status', 'stock_status']);
            $salesOrder?->update([
                'status' => 'awaiting_delivery',
                'delivery_status' => 'pending',
            ]);

            $this->audit->record('goods_issue', $issue->id, 'confirm_goods_issue', $actor, $old, $issue->fresh()->only(['status', 'confirmed_by', 'confirmed_at']));
            if ($salesOrder) {
                foreach (Task::where('tenant_id', $salesOrder->tenant_id)->where('source_type', 'SalesOrder')->where('source_id', $salesOrder->id)
                    ->where('task_type', 'warehouse_issue')->whereIn('status', ['new', 'in_progress', 'overdue'])->get() as $task) {
                    $this->tasks->changeStatus($task, $actor, 'completed', 'goods_issue_confirmed');
                }
                $this->audit->record('sales_order', $salesOrder->id, 'mark_sales_order_awaiting_delivery', $actor, $oldOrder, $salesOrder->fresh()->only(['status', 'stock_status', 'delivery_status']));
                $this->tasks->create($actor, [
                    'module' => 'sales',
                    'task_type' => 'delivery_confirmation',
                    'priority' => 'high',
                    'title' => 'Xác nhận giao hàng cho đơn '.$salesOrder->code,
                    'source_type' => 'SalesOrder',
                    'source_id' => $salesOrder->id,
                    'assignee_id' => $salesOrder->sales_owner_id,
                ]);
            }
            $this->events->publish($issue->tenant_id, 'GoodsIssueConfirmed', 'GoodsIssue', $issue->id, ['code' => $issue->code]);

            return $issue->refresh()->load('items.sku:id,sku_code,name');
        });
    }

    private function reserveSourceSalesOrderAfterReceipt(GoodsReceipt $receipt, User $actor): void
    {
        $po = PurchaseOrder::with('purchaseRequest')
            ->where('tenant_id', $receipt->tenant_id)
            ->find($receipt->purchase_order_id);

        $purchaseRequest = $po?->purchaseRequest;
        if (! $purchaseRequest || $purchaseRequest->source_type !== 'SalesOrder' || ! $purchaseRequest->source_id) {
            return;
        }

        $order = SalesOrder::with('items')
            ->where('tenant_id', $receipt->tenant_id)
            ->lockForUpdate()
            ->find($purchaseRequest->source_id);

        if (! $order || $order->stock_status !== 'shortage' || ! $this->canReserveSalesOrder($order)) {
            return;
        }

        $old = $order->only(['stock_status', 'status']);
        $this->reserveSalesOrderStock($order);
        $order->update(['stock_status' => 'reserved', 'status' => 'confirmed']);
        $this->ensureDraftIssue($order, $actor);

        Alert::where('tenant_id', $order->tenant_id)
            ->where('alert_type', 'stock_shortage')
            ->where('source_type', 'SalesOrder')
            ->where('source_id', $order->id)
            ->where('status', 'open')
            ->update(['status' => 'resolved']);

        $taskExists = Task::where('tenant_id', $order->tenant_id)
            ->where('task_type', 'warehouse_issue')
            ->where('source_type', 'SalesOrder')
            ->where('source_id', $order->id)
            ->exists();

        if (! $taskExists) {
            $this->tasks->create($actor, [
                'module' => 'inventory',
                'task_type' => 'warehouse_issue',
                'priority' => 'high',
                'title' => 'Xuat kho cho don '.$order->code,
                'description' => 'Hang da nhap kho, can tao phieu xuat cho don ban.',
                'source_type' => 'SalesOrder',
                'source_id' => $order->id,
                'assignee_id' => null,
            ]);
        }

        $this->audit->record('sales_order', $order->id, 'reserve_sales_order_after_receipt', $actor, $old, $order->fresh()->only(['stock_status', 'status']));
        $this->events->publish($order->tenant_id, 'SalesOrderReservedAfterReceipt', 'SalesOrder', $order->id, ['code' => $order->code]);
    }

    private function canReserveSalesOrder(SalesOrder $order): bool
    {
        foreach ($order->items->groupBy('sku_id') as $skuId => $items) {
            $available = InventoryBalance::where('tenant_id', $order->tenant_id)
                ->where('sku_id', $skuId)
                ->sum('available');

            if ($available < $items->sum('quantity')) {
                return false;
            }
        }

        return true;
    }

    private function reserveSalesOrderStock(SalesOrder $order): void
    {
        foreach ($order->items as $item) {
            $remaining = (float) $item->quantity;
            $balances = InventoryBalance::where('tenant_id', $order->tenant_id)
                ->where('sku_id', $item->sku_id)
                ->where('available', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($balances as $balance) {
                if ($remaining <= 0.0001) {
                    break;
                }

                $taken = min((float) $balance->available, $remaining);
                $balance->update([
                    'reserved' => $balance->reserved + $taken,
                    'available' => $balance->available - $taken,
                ]);
                $remaining -= $taken;
            }

            abort_if($remaining > 0.0001, 422, 'Khong du ton de giu hang cho don ban.');
        }
    }

    private function nextReceiptCode(int $tenantId): string
    {
        return $this->codes->next('goods_receipts', 'code', 'GR-', fn ($query) => $query->where('tenant_id', $tenantId));
    }

    private function nextIssueCode(int $tenantId): string
    {
        return $this->codes->next('goods_issues', 'code', 'GI-', fn ($query) => $query->where('tenant_id', $tenantId));
    }
}
