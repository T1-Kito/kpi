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
            $quotation = Quotation::where('tenant_id', $actor->tenant_id)->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            $allowed = $quotation->workflow_version == 2 ? ['accepted'] : ['ready', 'approved'];
            abort_if(! in_array($quotation->status, $allowed, true), 422, 'Báo giá cần duyệt, phát hành và ghi nhận khách đồng ý trước khi tạo đơn bán.');
            abort_if($quotation->workflow_version == 2 && (! $quotation->valid_until || $quotation->valid_until->endOfDay()->isPast()), 422, 'Báo giá đã hết hiệu lực.');
            abort_if($quotation->workflow_version == 2 && ! \App\Models\QuotationIssue::where('quotation_id', $quotation->id)->where('tenant_id', $actor->tenant_id)->where('customer_decision', 'accepted')->whereNotNull('customer_evidence')->exists(), 422, 'Chưa có bản phát hành và bằng chứng khách đồng ý.');

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
                'sales_owner_id' => $quotation->sales_owner_id ?? $actor->id,
                'subtotal_amount' => $quotation->workflow_version == 2 ? bcsub((string) $quotation->subtotal_amount, (string) $quotation->discount_amount, 2) : ($quotation->subtotal_amount ?: ($quotation->total_amount - $quotation->tax_amount)),
                'tax_amount' => $quotation->tax_amount ?: 0,
                'total_amount' => $quotation->total_amount,
                'payment_terms' => $quotation->payment_terms,
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

    public function confirm(SalesOrder $order, User $actor, array $fulfillment = []): SalesOrder
    {
        return DB::transaction(function () use ($order, $actor, $fulfillment) {
            $order = SalesOrder::where('tenant_id', $actor->tenant_id)->whereKey($order->id)->lockForUpdate()->firstOrFail()->load(['items', 'customer']);
            $type = $fulfillment['fulfillment_type'] ?? 'from_stock';
            if ($order->stock_status === 'reserved' || $order->fulfillment_type === 'supplier_direct') {
                abort_if(($order->fulfillment_type ?? 'from_stock') !== $type, 409, 'Đơn đã xác nhận; không được đổi cách giao hàng.');
                app(\App\Services\Inventory\InventoryService::class)->ensureDraftIssue($order, $actor);
                return $order->load('items.sku:id,sku_code,name');
            }
            abort_unless(in_array($order->status, ['draft', 'confirmed'], true), 422, 'Trạng thái đơn không cho phép xác nhận.');
            // Lock all candidate balances in a stable order before checking or reserving.
            $balances = InventoryBalance::where('tenant_id', $order->tenant_id)
                ->whereIn('sku_id', $order->items->pluck('sku_id'))->orderBy('id')->lockForUpdate()->get();
            $old = $order->only(['status', 'stock_status']);
            if (($fulfillment['fulfillment_type'] ?? 'from_stock') === 'supplier_direct') {
                $order->update(['fulfillment_type' => 'supplier_direct', 'supplier_delivery_note' => $fulfillment['supplier_delivery_note'] ?? null, 'stock_status' => 'not_required', 'status' => 'awaiting_delivery', 'delivery_status' => 'pending']);
                app(\App\Services\Inventory\InventoryService::class)->ensureDraftIssue($order, $actor);
                $this->tasks->create($actor, ['module' => 'inventory', 'task_type' => 'warehouse_issue', 'priority' => 'high', 'title' => 'Lập phiếu xuất không trừ tồn cho đơn '.$order->code, 'description' => 'Nhà cung cấp giao thẳng khách. Kho lập phiếu để kiểm tra chứng từ, không trừ tồn kho.', 'source_type' => 'SalesOrder', 'source_id' => $order->id, 'assignee_id' => null]);
                $this->audit->record('sales_order', $order->id, 'confirm_supplier_direct_sales_order', $actor, $old, $order->fresh()->only(['fulfillment_type', 'status', 'stock_status', 'delivery_status']));
                $this->events->publish($order->tenant_id, 'SalesOrderSupplierDirect', 'SalesOrder', $order->id, ['code' => $order->code]);
                return $order->refresh()->load('items.sku:id,sku_code,name');
            }
            $order->update(['fulfillment_type' => 'from_stock', 'supplier_delivery_note' => null]);
            $shortages = [];
            foreach ($order->items->groupBy('sku_id') as $skuId => $items) {
                $required = $items->sum('quantity');
                $available = $balances->where('sku_id', $skuId)->sum('available');

                if ($available < $required) {
                    $shortages[] = ['sku_id' => $skuId, 'required' => (float) $required, 'available' => (float) $available];
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
                    $remaining = (float) $item->quantity;
                    foreach ($balances->where('sku_id', $item->sku_id) as $balance) {
                        $quantity = min($remaining, max(0, (float) $balance->available));
                        if ($quantity <= 0) continue;
                        $balance->update(['reserved' => $balance->reserved + $quantity, 'available' => $balance->available - $quantity]);
                        $remaining -= $quantity;
                        if ($remaining <= 0) break;
                    }
                    abort_if($remaining > 0, 409, 'Tồn kho đã thay đổi. Vui lòng kiểm tra lại.');
                }

                $order->update(['stock_status' => 'reserved', 'status' => 'confirmed']);
                app(\App\Services\Inventory\InventoryService::class)->ensureDraftIssue($order, $actor);
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
