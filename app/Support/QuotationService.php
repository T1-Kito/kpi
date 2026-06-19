<?php

namespace App\Support;

use App\Models\Approval;
use App\Models\Quotation;
use App\Models\Sku;
use App\Models\User;
use App\Models\VkNotification;
use Illuminate\Support\Facades\DB;

class QuotationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly CodeGenerator $codes,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Quotation
    {
        return DB::transaction(function () use ($actor, $data) {
            $quotation = Quotation::create([
                'tenant_id' => $actor->tenant_id,
                'code' => $this->codes->next('quotations', 'code', 'QUO-', fn ($query) => $query->where('tenant_id', $actor->tenant_id)),
                'customer_id' => $data['customer_id'],
                'lead_id' => $data['lead_id'] ?? null,
                'duplicated_from_id' => $data['duplicated_from_id'] ?? null,
                'sales_owner_id' => $actor->id,
                'valid_until' => $data['valid_until'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'delivery_terms' => $data['delivery_terms'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => 'draft',
            ]);

            $summary = $this->replaceItemsAndRecalculate($quotation, $actor, $data['items']);
            $this->syncMarginApproval($quotation, $actor, $summary['margin']);

            $this->audit->record('quotation', $quotation->id, 'create_quotation', $actor, null, $quotation->toArray());
            $this->events->publish($actor->tenant_id, 'QuotationCreated', 'Quotation', $quotation->id, [
                'code' => $quotation->code,
                'margin_percent' => $summary['margin'],
            ]);

            return $quotation->refresh()->load('items.sku:id,sku_code,name,unit');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Quotation $quotation, User $actor, array $data): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor, $data) {
            abort_if($quotation->status === 'approved', 422, 'Bao gia da duyet nen khong the sua.');
            abort_if($quotation->salesOrders()->exists(), 422, 'Bao gia da tao don ban nen khong the sua.');

            $old = $quotation->load('items')->toArray();
            $quotation->update([
                'customer_id' => $data['customer_id'],
                'lead_id' => $data['lead_id'] ?? $quotation->lead_id,
                'valid_until' => $data['valid_until'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'delivery_terms' => $data['delivery_terms'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $summary = $this->replaceItemsAndRecalculate($quotation, $actor, $data['items']);
            $this->syncMarginApproval($quotation, $actor, $summary['margin']);

            $this->audit->record('quotation', $quotation->id, 'update_quotation', $actor, $old, $quotation->fresh()->toArray());
            $this->events->publish($quotation->tenant_id, 'QuotationUpdated', 'Quotation', $quotation->id, [
                'code' => $quotation->code,
                'margin_percent' => $summary['margin'],
            ]);

            return $quotation->refresh()->load('items.sku:id,sku_code,name,unit');
        });
    }

    public function approve(Quotation $quotation, User $actor, string $status, ?string $reason = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor, $status, $reason) {
            $approval = Approval::where('tenant_id', $quotation->tenant_id)
                ->where('source_type', 'Quotation')
                ->where('source_id', $quotation->id)
                ->where('status', 'pending')
                ->firstOrFail();

            $approval->update(['status' => $status, 'reason' => $reason, 'approver_id' => $actor->id, 'decided_at' => now()]);
            $quotation->update(['status' => $status === 'approved' ? 'approved' : 'rejected']);

            $this->audit->record('quotation', $quotation->id, $status.'_quotation', $actor, null, ['reason' => $reason]);
            $this->events->publish($quotation->tenant_id, 'QuotationApproved', 'Quotation', $quotation->id, ['status' => $status]);

            return $quotation->refresh()->load('items.sku:id,sku_code,name,unit');
        });
    }

    /** @param array<int, array<string, mixed>> $items */
    private function replaceItemsAndRecalculate(Quotation $quotation, User $actor, array $items): array
    {
        $quotation->items()->delete();
        $subtotal = 0;
        $tax = 0;
        $cost = 0;

        foreach ($items as $line) {
            $sku = Sku::where('tenant_id', $actor->tenant_id)->findOrFail($line['sku_id']);
            $quantity = (float) $line['quantity'];
            $unitPrice = (float) ($line['unit_price'] ?? $sku->sale_price);
            $unitCost = (float) $sku->cost_price;
            $vatRate = (float) ($line['vat_rate'] ?? 0);
            $lineSubtotal = $quantity * $unitPrice;
            $vatAmount = round($lineSubtotal * $vatRate / 100, 2);
            $lineTotal = $lineSubtotal + $vatAmount;
            $lineCost = $quantity * $unitCost;

            $quotation->items()->create([
                'sku_id' => $sku->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'line_subtotal' => $lineSubtotal,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'line_total' => $lineTotal,
                'line_cost' => $lineCost,
            ]);

            $subtotal += $lineSubtotal;
            $tax += $vatAmount;
            $cost += $lineCost;
        }

        $total = $subtotal + $tax;
        $margin = $subtotal > 0 ? (($subtotal - $cost) / $subtotal) * 100 : 0;
        $quotation->update([
            'subtotal_amount' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'total_cost' => $cost,
            'margin_percent' => $margin,
            'status' => $margin < 15 ? 'pending_approval' : 'ready',
        ]);

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $total, 'cost' => $cost, 'margin' => $margin];
    }

    private function syncMarginApproval(Quotation $quotation, User $actor, float $margin): void
    {
        if ($margin >= 15) {
            Approval::where('tenant_id', $quotation->tenant_id)
                ->where('source_type', 'Quotation')
                ->where('source_id', $quotation->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'reason' => 'Margin da dat nguong sau khi cap nhat.',
                    'decided_at' => now(),
                ]);
            return;
        }

        $approval = Approval::updateOrCreate(
            [
                'tenant_id' => $actor->tenant_id,
                'source_type' => 'Quotation',
                'source_id' => $quotation->id,
                'status' => 'pending',
            ],
            [
                'approver_id' => $actor->manager_id,
                'reason' => 'Margin thap hon 15%',
                'decided_at' => null,
            ],
        );

        $this->events->publish($actor->tenant_id, 'QuotationMarginLow', 'Quotation', $quotation->id, ['margin_percent' => $margin]);

        if ($actor->manager_id && $approval->wasRecentlyCreated) {
            VkNotification::create([
                'tenant_id' => $actor->tenant_id,
                'recipient_id' => $actor->manager_id,
                'title' => 'Bao gia can duyet margin thap',
                'message' => $quotation->code.' co margin '.round($margin, 2).'%',
                'source_type' => 'Quotation',
                'source_id' => $quotation->id,
                'action_url' => '/quotations/'.$quotation->id,
                'status' => 'unread',
            ]);
        }
    }
}
