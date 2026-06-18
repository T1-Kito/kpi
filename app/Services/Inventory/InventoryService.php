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
use App\Support\TaskService;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly TaskService $tasks,
    ) {
    }

    public function createReceiptFromPo(PurchaseOrder $po, User $actor, int $warehouseId, ?int $locationId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($po, $actor, $warehouseId, $locationId) {
            abort_if($po->status !== 'approved', 422, 'Don mua chua duoc duyet.');

            $receipt = GoodsReceipt::create([
                'tenant_id' => $po->tenant_id,
                'code' => $this->nextReceiptCode($po->tenant_id),
                'purchase_order_id' => $po->id,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'status' => 'draft',
            ]);

            foreach ($po->items as $item) {
                $receipt->items()->create([
                    'sku_id' => $item->sku_id,
                    'quantity' => $item->quantity,
                    'lot_no' => 'PO-'.$po->code,
                ]);
            }

            $this->audit->record('goods_receipt', $receipt->id, 'create_goods_receipt', $actor, null, $receipt->toArray());

            return $receipt->load('items.sku:id,sku_code,name');
        });
    }

    public function confirmReceipt(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $actor) {
            abort_if($receipt->status === 'confirmed', 422, 'Phieu nhap da duoc xac nhan.');
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

    public function createIssueFromSalesOrder(SalesOrder $order, User $actor, int $warehouseId, ?int $locationId = null): GoodsIssue
    {
        return DB::transaction(function () use ($order, $actor, $warehouseId, $locationId) {
            abort_if($order->stock_status !== 'reserved', 422, 'Don ban chua giu hang.');

            $existingIssue = GoodsIssue::where('tenant_id', $order->tenant_id)
                ->where('sales_order_id', $order->id)
                ->whereIn('status', ['draft', 'confirmed'])
                ->lockForUpdate()
                ->first();
            if ($existingIssue) {
                abort(422, "Đơn bán {$order->code} đã có phiếu xuất {$existingIssue->code}. Vui lòng mở phiếu xuất hiện có để xử lý tiếp.");
            }

            foreach ($order->items as $item) {
                $reservedQty = InventoryBalance::where('tenant_id', $order->tenant_id)
                    ->where('warehouse_id', $warehouseId)
                    ->where('sku_id', $item->sku_id)
                    ->sum('reserved');

                abort_if($reservedQty < $item->quantity, 422, 'Kho chon chua du hang da giu de xuat.');
            }

            $issue = GoodsIssue::create([
                'tenant_id' => $order->tenant_id,
                'code' => $this->nextIssueCode($order->tenant_id),
                'sales_order_id' => $order->id,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
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

    public function confirmIssue(GoodsIssue $issue, User $actor): GoodsIssue
    {
        return DB::transaction(function () use ($issue, $actor) {
            abort_if($issue->status === 'confirmed', 422, 'Phieu xuat da duoc xac nhan.');
            $old = $issue->only(['status', 'confirmed_by', 'confirmed_at']);

            foreach ($issue->items as $item) {
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
            ->find($purchaseRequest->source_id);

        if (! $order || $order->stock_status !== 'shortage' || ! $this->canReserveSalesOrder($order)) {
            return;
        }

        $old = $order->only(['stock_status', 'status']);
        $this->reserveSalesOrderStock($order);
        $order->update(['stock_status' => 'reserved', 'status' => 'confirmed']);

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
        foreach ($order->items as $item) {
            $available = InventoryBalance::where('tenant_id', $order->tenant_id)
                ->where('sku_id', $item->sku_id)
                ->sum('available');

            if ($available < $item->quantity) {
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
        return 'GR-'.str_pad((string) (GoodsReceipt::where('tenant_id', $tenantId)->count() + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextIssueCode(int $tenantId): string
    {
        return 'GI-'.str_pad((string) (GoodsIssue::where('tenant_id', $tenantId)->count() + 1), 5, '0', STR_PAD_LEFT);
    }
}
