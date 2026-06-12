<?php

namespace App\Services\Procurement;

use App\Models\Approval;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessEventPublisher;
use App\Support\TaskService;
use Illuminate\Support\Facades\DB;

class ProcurementService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly TaskService $tasks,
    ) {
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

    public function createPoFromPr(PurchaseRequest $pr, User $actor, int $supplierId, ?string $expectedDeliveryDate = null): PurchaseOrder
    {
        return DB::transaction(function () use ($pr, $actor, $supplierId, $expectedDeliveryDate) {
            abort_if($pr->status !== 'approved', 422, 'Yêu cầu mua chưa được duyệt.');
            Supplier::where('tenant_id', $pr->tenant_id)->findOrFail($supplierId);

            $po = PurchaseOrder::create([
                'tenant_id' => $pr->tenant_id,
                'code' => $this->nextPoCode($pr->tenant_id),
                'purchase_request_id' => $pr->id,
                'supplier_id' => $supplierId,
                'created_by' => $actor->id,
                'expected_delivery_date' => $expectedDeliveryDate,
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

    public function approvePo(PurchaseOrder $po, User $actor, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $reason) {
            $old = $po->status;
            $po->update(['status' => 'approved']);
            Approval::where('tenant_id', $po->tenant_id)
                ->where('source_type', 'PurchaseOrder')
                ->where('source_id', $po->id)
                ->where('status', 'pending')
                ->update(['status' => 'approved', 'approver_id' => $actor->id, 'reason' => $reason, 'decided_at' => now()]);

            $this->audit->record('purchase_order', $po->id, 'approve_purchase_order', $actor, ['status' => $old], ['status' => 'approved'], null, $reason);
            $this->events->publish($po->tenant_id, 'PurchaseOrderApproved', 'PurchaseOrder', $po->id);

            return $po->refresh()->load(['supplier:id,code,name', 'items.sku:id,sku_code,name']);
        });
    }

    private function nextPrCode(int $tenantId): string
    {
        return 'PR-'.str_pad((string) (PurchaseRequest::where('tenant_id', $tenantId)->count() + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextPoCode(int $tenantId): string
    {
        return 'PO-'.str_pad((string) (PurchaseOrder::where('tenant_id', $tenantId)->count() + 1), 5, '0', STR_PAD_LEFT);
    }
}
