<?php

namespace App\Support;

use App\Models\Alert;
use App\Models\InventoryBalance;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Procurement\ProcurementService;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly TaskService $tasks,
        private readonly ProcurementService $procurement,
        private readonly CodeGenerator $codes,
    ) {
    }

    public function createFromQuotation(Quotation $quotation, User $actor): SalesOrder
    {
        return DB::transaction(function () use ($quotation, $actor) {
            abort_if(! in_array($quotation->status, ['ready', 'approved'], true), 422, 'Báo giá chưa sẵn sàng tạo đơn bán.');

            $existing = SalesOrder::where('tenant_id', $actor->tenant_id)
                ->where('quotation_id', $quotation->id)
                ->whereNotIn('status', ['cancelled'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                abort(422, "Báo giá {$quotation->code} đã tạo đơn bán {$existing->code}. Vui lòng mở đơn bán hiện có để xử lý tiếp.");
            }

            $order = SalesOrder::create([
                'tenant_id' => $actor->tenant_id,
                'code' => $this->codes->next('sales_orders', 'code', 'SO-', fn ($query) => $query->where('tenant_id', $actor->tenant_id)),
                'quotation_id' => $quotation->id,
                'customer_id' => $quotation->customer_id,
                'sales_owner_id' => $actor->id,
                'subtotal_amount' => $quotation->subtotal_amount ?: ($quotation->total_amount - $quotation->tax_amount),
                'tax_amount' => $quotation->tax_amount ?: 0,
                'total_amount' => $quotation->total_amount,
                'stock_status' => 'unchecked',
                'status' => 'draft',
            ]);

            foreach ($quotation->items as $item) {
                $order->items()->create([
                    'sku_id' => $item->sku_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_subtotal' => $item->line_subtotal ?: ($item->line_total - $item->vat_amount),
                    'vat_rate' => $item->vat_rate ?: 0,
                    'vat_amount' => $item->vat_amount ?: 0,
                    'line_total' => $item->line_total,
                ]);
            }

            $this->audit->record('sales_order', $order->id, 'create_sales_order', $actor, null, $order->toArray());
            $this->events->publish($actor->tenant_id, 'SalesOrderCreated', 'SalesOrder', $order->id, ['code' => $order->code]);

            return $order->load('items.sku:id,sku_code,name');
        });
    }

    public function confirm(SalesOrder $order, User $actor): SalesOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $old = $order->only(['status', 'stock_status']);
            $shortages = [];
            foreach ($order->items as $item) {
                $available = InventoryBalance::where('tenant_id', $order->tenant_id)
                    ->where('sku_id', $item->sku_id)
                    ->sum('available');

                if ($available < $item->quantity) {
                    $shortages[] = ['sku_id' => $item->sku_id, 'required' => (float) $item->quantity, 'available' => (float) $available];
                }
            }

            if ($shortages) {
                $order->update(['stock_status' => 'shortage', 'status' => 'confirmed']);
                Alert::firstOrCreate(
                    ['tenant_id' => $order->tenant_id, 'alert_type' => 'stock_shortage', 'source_type' => 'SalesOrder', 'source_id' => $order->id],
                    [
                        'recipient_id' => $actor->id,
                        'level' => 'high',
                        'title' => 'Đơn hàng thiếu tồn: '.$order->code,
                        'message' => 'Sales order cần mua bổ sung trước khi xuất kho.',
                        'action_url' => '/sales-orders/'.$order->id,
                        'status' => 'open',
                    ],
                );
                $this->procurement->createDraftPrFromSalesOrder($order, $actor, $shortages);
                $this->events->publish($order->tenant_id, 'SalesOrderStockShortage', 'SalesOrder', $order->id, ['shortages' => $shortages]);
            } else {
                foreach ($order->items as $item) {
                    $balance = InventoryBalance::where('tenant_id', $order->tenant_id)
                        ->where('sku_id', $item->sku_id)
                        ->where('available', '>=', $item->quantity)
                        ->orderBy('id')
                        ->firstOrFail();
                    $balance->update([
                        'reserved' => $balance->reserved + $item->quantity,
                        'available' => $balance->available - $item->quantity,
                    ]);
                }

                $order->update(['stock_status' => 'reserved', 'status' => 'confirmed']);
                $this->tasks->create($actor, [
                    'module' => 'inventory',
                    'task_type' => 'warehouse_issue',
                    'priority' => 'high',
                    'title' => 'Xuất kho cho đơn '.$order->code,
                    'source_type' => 'SalesOrder',
                    'source_id' => $order->id,
                    'assignee_id' => null,
                ]);
                $this->events->publish($order->tenant_id, 'SalesOrderConfirmed', 'SalesOrder', $order->id, ['stock_status' => 'reserved']);
            }

            $this->audit->record('sales_order', $order->id, 'confirm_sales_order', $actor, $old, $order->fresh()->only(['status', 'stock_status']));

            return $order->refresh()->load('items.sku:id,sku_code,name');
        });
    }
}
