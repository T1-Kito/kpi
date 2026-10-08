<?php

namespace App\Services\Procurement;

use App\Models\Approval;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessEventPublisher;
use App\Support\CodeGenerator;
use App\Support\TaskService;
use Illuminate\Support\Facades\DB;

class ProcurementService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly TaskService $tasks,
        private readonly CodeGenerator $codes,
    ) {
    }

    /** @param array<int, array<string, mixed>> $items */
    public function createManualPr(User $actor, array $items, ?string $reason = null): PurchaseRequest
    {
        return DB::transaction(function () use ($actor, $items, $reason) {
            $pr = PurchaseRequest::create([
                'tenant_id' => $actor->tenant_id,
                'code' => $this->nextPrCode($actor->tenant_id),
                'source_type' => 'Manual',
                'source_id' => null,
                'requested_by' => $actor->id,
                'reason' => $reason ?: 'Yêu cầu mua thủ công',
                'status' => 'draft',
            ]);

            foreach ($items as $line) {
                $pr->items()->create([
                    'sku_id' => $line['sku_id'],
                    'quantity' => (float) $line['quantity'],
                    'available_qty' => (float) ($line['available_qty'] ?? 0),
                ]);
            }

            $this->audit->record('purchase_request', $pr->id, 'create_purchase_request_manual', $actor, null, $pr->toArray());
            $this->events->publish($actor->tenant_id, 'PurchaseRequestCreated', 'PurchaseRequest', $pr->id, ['source' => 'manual']);

            return $pr->load('items.sku:id,sku_code,name,unit');
        });
    }

    /** @param array<int, array<string, mixed>> $shortages */
    public function createDraftPrFromSalesOrder(SalesOrder $order, User $actor, array $shortages): PurchaseRequest
    {
        return DB::transaction(function () use ($order, $actor, $shortages) {
            $existing = PurchaseRequest::where('tenant_id', $order->tenant_id)
                ->where('source_type', 'SalesOrder')
                ->where('source_id', $order->id)
                ->first();

            if ($existing) {
                return $existing->load('items.sku:id,sku_code,name');
            }

            $pr = PurchaseRequest::create([
                'tenant_id' => $order->tenant_id,
                'code' => $this->nextPrCode($order->tenant_id),
                'source_type' => 'SalesOrder',
                'source_id' => $order->id,
                'requested_by' => $actor->id,
                'reason' => 'Tự sinh do đơn bán thiếu tồn '.$order->code,
                'status' => 'draft',
            ]);

            foreach ($shortages as $line) {
                $pr->items()->create([
                    'sku_id' => $line['sku_id'],
                    'quantity' => max(0, (float) $line['required'] - (float) $line['available']),
                    'available_qty' => (float) $line['available'],
                ]);
            }

            $this->tasks->create($actor, [
                'module' => 'procurement',
                'task_type' => 'purchase_request',
                'priority' => 'normal',
                'title' => 'Xử lý yêu cầu mua từ đơn '.$order->code,
                'description' => 'Đơn bán thiếu tồn, cần kiểm tra yêu cầu mua nháp và tạo đơn mua.',
                'source_type' => 'PurchaseRequest',
                'source_id' => $pr->id,
                'assignee_id' => null,
            ]);

            $this->audit->record('purchase_request', $pr->id, 'create_purchase_request_draft', $actor, null, $pr->toArray());
            $this->events->publish($order->tenant_id, 'PurchaseRequestCreated', 'PurchaseRequest', $pr->id, ['source_order' => $order->code]);

            return $pr->load('items.sku:id,sku_code,name');
        });
    }

    public function approvePr(PurchaseRequest $pr, User $actor, ?string $reason = null): PurchaseRequest
    {
        return DB::transaction(function () use ($pr, $actor, $reason) {
            $old = $pr->status;
            $pr->update(['status' => 'approved']);
            $this->audit->record('purchase_request', $pr->id, 'approve_purchase_request', $actor, ['status' => $old], ['status' => 'approved'], null, $reason);
            $this->events->publish($pr->tenant_id, 'PurchaseRequestApproved', 'PurchaseRequest', $pr->id);

            return $pr->refresh()->load('items.sku:id,sku_code,name');
        });
    }

    public function rejectPr(PurchaseRequest $pr, User $actor, ?string $reason = null): PurchaseRequest
    {
        return DB::transaction(function () use ($pr, $actor, $reason) {
            abort_if(! in_array($pr->status, ['draft', 'pending'], true), 422, 'Yêu cầu mua không còn ở trạng thái chờ duyệt.');
            $old = $pr->status;
            $pr->update(['status' => 'rejected']);
            $this->audit->record('purchase_request', $pr->id, 'reject_purchase_request', $actor, ['status' => $old], ['status' => 'rejected'], null, $reason);
            $this->events->publish($pr->tenant_id, 'PurchaseRequestRejected', 'PurchaseRequest', $pr->id, ['reason' => $reason]);

            return $pr->refresh()->load('items.sku:id,sku_code,name');
        });
    }

    public function createPoFromPr(PurchaseRequest $pr, User $actor, int $supplierId, ?string $expectedDeliveryDate = null, array $terms = []): PurchaseOrder
    {
        return DB::transaction(function () use ($pr, $actor, $supplierId, $expectedDeliveryDate, $terms) {
            abort_if($pr->status !== 'approved', 422, 'Yêu cầu mua chưa được duyệt.');
            abort_if(PurchaseOrder::where('tenant_id', $pr->tenant_id)->where('purchase_request_id', $pr->id)->whereNotIn('status', ['cancelled', 'rejected'])->exists(), 422, 'Yêu cầu này đã có đơn mua. Không thể tạo trùng.');
            Supplier::where('tenant_id', $pr->tenant_id)->findOrFail($supplierId);

            $po = PurchaseOrder::create([
                'tenant_id' => $pr->tenant_id,
                'code' => $this->nextPoCode($pr->tenant_id),
                'purchase_request_id' => $pr->id,
                'supplier_id' => $supplierId,
                'receiving_warehouse_id' => $terms['receiving_warehouse_id'] ?? $this->singleActiveWarehouse($pr->tenant_id),
                'created_by' => $actor->id,
                'expected_delivery_date' => $expectedDeliveryDate,
                'payment_terms' => $terms['payment_terms'] ?? null,
                'delivery_terms' => $terms['delivery_terms'] ?? null,
                'warranty_terms' => $terms['warranty_terms'] ?? null,
                'shipping_fee' => (float) ($terms['shipping_fee'] ?? 0),
                'status' => 'draft',
            ]);

            $total = 0;
            foreach ($pr->items as $item) {
                $unitPrice = (float) ($item->sku?->cost_price ?? 0);
                $lineTotal = $unitPrice * (float) $item->quantity;
                $po->items()->create([
                    'sku_id' => $item->sku_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ]);
                $total += $lineTotal;
            }
            $po->update(['total_amount' => $total]);
            $po->update(['approval_flow' => app(\App\Support\OperationalApproval::class)->flow($po->tenant_id, 'purchase_order')]);

            Approval::create([
                'tenant_id' => $po->tenant_id,
                'source_type' => 'PurchaseOrder',
                'source_id' => $po->id,
                'approver_id' => $actor->manager_id,
                'status' => 'pending',
                'reason' => 'Duyệt đơn mua',
            ]);

            $this->audit->record('purchase_order', $po->id, 'create_purchase_order', $actor, null, $po->toArray());
            $this->events->publish($po->tenant_id, 'PurchaseOrderCreated', 'PurchaseOrder', $po->id, ['code' => $po->code]);

            return $po->load(['supplier:id,code,name', 'items.sku:id,sku_code,name']);
        });
    }

    public function createPoFromSupplierQuotation(SupplierQuotation $quotation, User $actor, ?string $expectedDeliveryDate = null, array $terms = []): PurchaseOrder
    {
        return DB::transaction(function () use ($quotation, $actor, $expectedDeliveryDate, $terms) {
            abort_if($quotation->tenant_id !== $actor->tenant_id, 404);
            abort_if($quotation->status !== 'selected', 422, 'Chỉ báo giá nhà cung cấp đã chọn mới được tạo đơn mua.');
            abort_if(! $quotation->purchase_request_id, 422, 'Báo giá chưa gắn yêu cầu mua.');

            $quotation->load(['purchaseRequest.items', 'lines.sku']);
            $pr = $quotation->purchaseRequest;
            abort_if(! $pr || $pr->status !== 'approved', 422, 'Yêu cầu mua của báo giá chưa được duyệt.');
            abort_if(PurchaseOrder::where('tenant_id', $pr->tenant_id)->where('purchase_request_id', $pr->id)->whereNotIn('status', ['cancelled', 'rejected'])->exists(), 422, 'Yêu cầu này đã có đơn mua. Không thể tạo trùng.');
            $limits = $pr->items->groupBy('sku_id')->map(fn ($items) => $items->sum('quantity'));
            foreach ($quotation->lines->groupBy('sku_id') as $skuId => $lines) {
                abort_if(!$limits->has($skuId) || $lines->sum('quantity') > (float)$limits[$skuId], 422, 'Số lượng mua vượt yêu cầu đã duyệt.');
            }

            $po = PurchaseOrder::create([
                'tenant_id' => $quotation->tenant_id,
                'code' => $this->nextPoCode($quotation->tenant_id),
                'purchase_request_id' => $quotation->purchase_request_id,
                'supplier_quotation_id' => $quotation->id,
                'supplier_id' => $quotation->supplier_id,
                'receiving_warehouse_id' => $terms['receiving_warehouse_id'] ?? $this->singleActiveWarehouse($quotation->tenant_id),
                'created_by' => $actor->id,
                'expected_delivery_date' => $expectedDeliveryDate,
                'payment_terms' => $terms['payment_terms'] ?? null,
                'delivery_terms' => $terms['delivery_terms'] ?? null,
                'warranty_terms' => $terms['warranty_terms'] ?? null,
                'shipping_fee' => (float) ($terms['shipping_fee'] ?? 0),
                'total_amount' => $quotation->total_amount,
                'approval_flow' => app(\App\Support\OperationalApproval::class)->flow($quotation->tenant_id, 'purchase_order'),
                'status' => 'draft',
            ]);

            foreach ($quotation->lines as $line) {
                $po->items()->create([
                    'sku_id' => $line->sku_id,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'line_total' => $line->line_total,
                ]);
            }

            Approval::create([
                'tenant_id' => $po->tenant_id,
                'source_type' => 'PurchaseOrder',
                'source_id' => $po->id,
                'approver_id' => $actor->manager_id,
                'status' => 'pending',
                'reason' => 'Duyệt đơn mua từ báo giá nhà cung cấp '.$quotation->code,
            ]);

            $this->audit->record('purchase_order', $po->id, 'create_purchase_order_from_supplier_quotation', $actor, null, $po->toArray());
            $this->events->publish($po->tenant_id, 'PurchaseOrderCreated', 'PurchaseOrder', $po->id, ['code' => $po->code, 'supplier_quotation' => $quotation->code]);

            return $po->load(['supplier:id,code,name', 'supplierQuotation:id,code,total_amount,status', 'items.sku:id,sku_code,name']);
        });
    }

    public function approvePo(PurchaseOrder $po, User $actor, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $reason) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            abort_if($po->tenant_id !== $actor->tenant_id, 404);
            abort_if($po->status !== 'draft', 422, 'Đơn mua không còn chờ duyệt.');
            $flow = $po->approval_flow;
            if ($flow) {
                $index = collect($flow)->search(fn ($step) => $step['user_id'] === $actor->id);
                abort_if($index === false, 403, 'Bạn không thuộc danh sách duyệt đơn này.');
                abort_if(!empty($flow[$index]['decided_at']), 422, 'Bạn đã duyệt đơn này.');
                abort_if($index > 0 && empty($flow[$index - 1]['decided_at']), 422, 'Chưa hoàn tất cấp duyệt trước.');
                if ($flow[$index]['final']) abort_if(collect($flow)->reject(fn ($step) => $step['final'])->contains(fn ($step) => empty($step['decided_at'])), 422, 'Chưa đủ người duyệt trước giám đốc.');
                $flow[$index]['decided_at'] = now()->toIso8601String();
                $flow[$index]['reason'] = $reason;
                $po->update(['approval_flow' => $flow]);
                $this->audit->record('purchase_order', $po->id, 'approve_purchase_order_step', $actor, null, ['step' => $index + 1], null, $reason);
                if (collect($flow)->contains(fn ($step) => empty($step['decided_at']))) return $po->refresh();
            }
            $old = $po->status;
            $po->update(['status' => 'approved']);
            Approval::where('tenant_id', $po->tenant_id)
                ->where('source_type', 'PurchaseOrder')
                ->where('source_id', $po->id)
                ->where('status', 'pending')
                ->update(['status' => 'approved', 'approver_id' => $actor->id, 'reason' => $reason, 'decided_at' => now()]);

            $this->audit->record('purchase_order', $po->id, 'approve_purchase_order', $actor, ['status' => $old], ['status' => 'approved'], null, $reason);
            $this->events->publish($po->tenant_id, 'PurchaseOrderApproved', 'PurchaseOrder', $po->id);

            abort_if(! $po->receiving_warehouse_id, 422, 'Đơn mua chưa có kho nhận hàng.');
            app(\App\Services\Inventory\InventoryService::class)->createReceiptFromPo(
                $po->load('items'), $actor, (int) $po->receiving_warehouse_id,
            );

            return $po->refresh()->load(['supplier:id,code,name', 'items.sku:id,sku_code,name', 'receivingWarehouse:id,code,name', 'goodsReceipt:id,purchase_order_id,code,status,warehouse_id']);
        });
    }

    public function rejectPo(PurchaseOrder $po, User $actor, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $reason) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            abort_if($po->tenant_id !== $actor->tenant_id, 404);
            if ($po->approval_flow) {
                $step = collect($po->approval_flow)->firstWhere('user_id', $actor->id);
                abort_if(!$step || !empty($step['decided_at']), 403, 'Bạn không có bước duyệt đang chờ trên đơn này.');
                if ($step['final']) abort_if(collect($po->approval_flow)->reject(fn ($item) => $item['final'])->contains(fn ($item) => empty($item['decided_at'])), 422, 'Chưa đủ người duyệt trước giám đốc.');
            }
            abort_if($po->status !== 'draft', 422, 'Đơn mua không còn ở trạng thái chờ duyệt.');
            $old = $po->status;
            $po->update(['status' => 'rejected']);
            Approval::where('tenant_id', $po->tenant_id)
                ->where('source_type', 'PurchaseOrder')
                ->where('source_id', $po->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected', 'approver_id' => $actor->id, 'reason' => $reason, 'decided_at' => now()]);

            $this->audit->record('purchase_order', $po->id, 'reject_purchase_order', $actor, ['status' => $old], ['status' => 'rejected'], null, $reason);
            $this->events->publish($po->tenant_id, 'PurchaseOrderRejected', 'PurchaseOrder', $po->id, ['reason' => $reason]);

            return $po->refresh()->load(['supplier:id,code,name', 'items.sku:id,sku_code,name']);
        });
    }

    private function nextPrCode(int $tenantId): string
    {
        return $this->codes->next('purchase_requests', 'code', 'PR-', fn ($query) => $query->where('tenant_id', $tenantId));
    }

    private function singleActiveWarehouse(int $tenantId): ?int
    {
        $ids = \App\Models\Warehouse::where('tenant_id', $tenantId)->where('status', 'active')->limit(2)->pluck('id');
        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private function nextPoCode(int $tenantId): string
    {
        return $this->codes->next('purchase_orders', 'code', 'PO-', fn ($query) => $query->where('tenant_id', $tenantId));
    }
}
