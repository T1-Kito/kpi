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
                'code' => $data['code'] ?? $this->codes->next('quotations', 'code', 'QUO-', fn ($query) => $query->where('tenant_id', $actor->tenant_id)),
                'customer_id' => $data['customer_id'],
                'lead_id' => $data['lead_id'] ?? null,
                'sales_owner_id' => $actor->id,
                'status' => 'draft',
            ]);

            $subtotal = 0;
            $tax = 0;
            $cost = 0;
            foreach ($data['items'] as $line) {
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
            $status = $margin < 15 ? 'pending_approval' : 'ready';
            $quotation->update([
                'subtotal_amount' => $subtotal,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'total_cost' => $cost,
                'margin_percent' => $margin,
                'status' => $status,
            ]);

            $this->audit->record('quotation', $quotation->id, 'create_quotation', $actor, null, $quotation->toArray());
            $this->events->publish($actor->tenant_id, 'QuotationCreated', 'Quotation', $quotation->id, ['code' => $quotation->code, 'margin_percent' => $margin]);

            if ($margin < 15) {
                Approval::create([
                    'tenant_id' => $actor->tenant_id,
                    'source_type' => 'Quotation',
                    'source_id' => $quotation->id,
                    'approver_id' => $actor->manager_id,
                    'status' => 'pending',
                    'reason' => 'Margin thấp hơn 15%',
                ]);
                $this->events->publish($actor->tenant_id, 'QuotationMarginLow', 'Quotation', $quotation->id, ['margin_percent' => $margin]);

                if ($actor->manager_id) {
                    VkNotification::create([
                        'tenant_id' => $actor->tenant_id,
                        'recipient_id' => $actor->manager_id,
                        'title' => 'Báo giá cần duyệt margin thấp',
                        'message' => $quotation->code.' có margin '.round($margin, 2).'%',
                        'source_type' => 'Quotation',
                        'source_id' => $quotation->id,
                        'action_url' => '/quotations/'.$quotation->id,
                        'status' => 'unread',
                    ]);
                }
            }

            return $quotation->load('items.sku:id,sku_code,name');
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

            return $quotation->refresh()->load('items.sku:id,sku_code,name');
        });
    }
}
