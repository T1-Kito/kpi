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
            $customer = \App\Models\Customer::where('tenant_id', $actor->tenant_id)->findOrFail($data['customer_id']);
            abort_if($customer->status !== 'active' || $customer->merged_into_id, 422, 'Chỉ được lập báo giá mới cho khách hàng đang hoạt động.');
            $defaultTerm = \App\Models\SalesPaymentTerm::where('tenant_id', $actor->tenant_id)
                ->where('is_active', true)->where('is_default', true)->orderBy('id')->first();
            $defaultPrice = \App\Models\SalesPriceBook::where('tenant_id', $actor->tenant_id)
                ->where('is_active', true)->where('is_default', true)->orderBy('id')->first();
            if (! empty($data['price_book_id'])) $defaultPrice = \App\Models\SalesPriceBook::where('tenant_id', $actor->tenant_id)->where('is_active', true)->findOrFail($data['price_book_id']);
            elseif (array_key_exists('price_book_id', $data)) $defaultPrice = null;
            if (! empty($data['payment_term_id'])) $defaultTerm = \App\Models\SalesPaymentTerm::where('tenant_id', $actor->tenant_id)->where('is_active', true)->findOrFail($data['payment_term_id']);
            abort_if($defaultPrice && (($defaultPrice->effective_from && $defaultPrice->effective_from->startOfDay()->isFuture()) || ($defaultPrice->effective_to && $defaultPrice->effective_to->endOfDay()->isPast())), 422, 'Chính sách giá chưa hoặc hết hiệu lực.');
            $quotation = Quotation::create([
                'tenant_id' => $actor->tenant_id,
                'workflow_version' => 2,
                'created_by' => $actor->id,
                'contact_id' => $customer->contacts()->where('is_primary', true)->value('id'),
                'code' => $this->codes->next('quotations', 'code', 'QUO-', fn ($query) => $query->where('tenant_id', $actor->tenant_id)),
                'customer_id' => $data['customer_id'],
                'lead_id' => $data['lead_id'] ?? null,
                'deal_id' => $data['deal_id'] ?? null,
                'price_book_id' => array_key_exists('price_book_id', $data) ? $data['price_book_id'] : $defaultPrice?->id,
                'payment_term_id' => $data['payment_term_id'] ?? $defaultTerm?->id,
                'duplicated_from_id' => $data['duplicated_from_id'] ?? null,
                'sales_owner_id' => ! empty($data['deal_id'])
                    ? \App\Models\Deal::where('tenant_id', $actor->tenant_id)->findOrFail($data['deal_id'])->owner_id
                    : $actor->id,
                'valid_until' => $data['valid_until'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? $customer->payment_terms ?? $defaultTerm?->name,
                'discount_percent' => $data['discount_percent'] ?? $defaultPrice?->discount_percent ?? 0,
                'delivery_terms' => $data['delivery_terms'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => 'draft',
            ]);

            $summary = $this->replaceItemsAndRecalculate($quotation, $actor, $data['items']);
            // Drafts are submitted explicitly; no approval is recorded while editing.

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
            $quotation = Quotation::where('tenant_id', $actor->tenant_id)->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            abort_if($quotation->workflow_version == 2 && $quotation->status !== 'draft', 422, 'Báo giá đã khóa. Hãy tạo phiên bản mới để sửa.');
            abort_if($quotation->status === 'approved', 422, 'Bao gia da duyet nen khong the sua.');
            abort_if($quotation->salesOrders()->exists(), 422, 'Bao gia da tao don ban nen khong the sua.');
            $customer = \App\Models\Customer::where('tenant_id', $actor->tenant_id)->where('status', 'active')->whereNull('merged_into_id')->findOrFail($data['customer_id']);
            $term = ! empty($data['payment_term_id']) ? \App\Models\SalesPaymentTerm::where('tenant_id', $actor->tenant_id)->where('is_active', true)->findOrFail($data['payment_term_id']) : $quotation->paymentTerm;
            $priceBook = array_key_exists('price_book_id', $data) ? (! empty($data['price_book_id']) ? \App\Models\SalesPriceBook::where('tenant_id', $actor->tenant_id)->where('is_active', true)->findOrFail($data['price_book_id']) : null) : $quotation->priceBook;
            abort_if($priceBook && (($priceBook->effective_from && $priceBook->effective_from->startOfDay()->isFuture()) || ($priceBook->effective_to && $priceBook->effective_to->endOfDay()->isPast())), 422, 'Chính sách giá chưa hoặc hết hiệu lực.');

            $old = $quotation->load('items')->toArray();
            $quotation->update([
                'customer_id' => $data['customer_id'],
                'contact_id' => \App\Models\CustomerContact::where('customer_id', $data['customer_id'])->where('is_primary', true)->value('id'),
                'lead_id' => $data['lead_id'] ?? $quotation->lead_id,
                'deal_id' => $data['deal_id'] ?? $quotation->deal_id,
                'price_book_id' => $priceBook?->id,
                'payment_term_id' => $data['payment_term_id'] ?? $quotation->payment_term_id,
                'valid_until' => $data['valid_until'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? $customer->payment_terms ?? $term?->name,
                'delivery_terms' => $data['delivery_terms'] ?? null,
                'note' => $data['note'] ?? null,
                'discount_percent' => $data['discount_percent'] ?? ($priceBook?->id !== $quotation->price_book_id ? ($priceBook?->discount_percent ?? 0) : $quotation->discount_percent),
            ]);

            $summary = $this->replaceItemsAndRecalculate($quotation, $actor, $data['items']);
            if ($quotation->workflow_version != 2) $this->syncMarginApproval($quotation, $actor, $summary['margin']);

            $this->audit->record('quotation', $quotation->id, 'update_quotation', $actor, $old, $quotation->fresh()->toArray());
            $this->events->publish($quotation->tenant_id, 'QuotationUpdated', 'Quotation', $quotation->id, [
                'code' => $quotation->code,
                'margin_percent' => $summary['margin'],
            ]);

            return $quotation->refresh()->load('items.sku:id,sku_code,name,unit');
        });
    }

    public function deleteDraft(Quotation $quotation, User $actor): void
    {
        DB::transaction(function () use ($quotation, $actor) {
            $quotation = Quotation::where('tenant_id', $actor->tenant_id)->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            abort_unless($quotation->status === 'draft' && ! $quotation->salesOrders()->exists(), 422, 'Chỉ xóa được báo giá nháp chưa có đơn hàng.');
            abort_if($quotation->revision_root_id, 422, 'Phiên bản sửa đổi phải được giữ lại để bảo toàn lịch sử.');
            $this->audit->record('quotation', $quotation->id, 'delete_draft_quotation', $actor, $quotation->toArray(), null);
            Approval::where('tenant_id', $quotation->tenant_id)->where('source_type', 'Quotation')->where('source_id', $quotation->id)->delete();
            $quotation->items()->delete();
            $quotation->delete();
            $this->events->publish($actor->tenant_id, 'QuotationDeleted', 'Quotation', $quotation->id, ['code' => $quotation->code]);
        });
    }

    public function approve(Quotation $quotation, User $actor, string $status, ?string $reason = null): Quotation
    {
        if ($quotation->workflow_version == 2) return app(QuotationWorkflow::class)->decide($quotation, $actor, $status, $reason);
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
        if ($quotation->workflow_version == 2) return $this->calculateDraft($quotation, $actor, $items);
        $quotation->items()->delete();
        $subtotal = 0;
        $tax = 0;
        $cost = 0;

        foreach ($items as $line) {
            $sku = Sku::where('tenant_id', $actor->tenant_id)->findOrFail($line['sku_id']);
            $quantity = (float) $line['quantity'];
            $unitPrice = (float) ($line['unit_price'] ?? $sku->sale_price);
            $listPrice = $unitPrice;
            if ($quotation->workflow_version == 2) $unitPrice = round($listPrice * (100 - (float) $quotation->discount_percent) / 100, 2);
            $lineName = $sku->name;
            $lineUnit = $sku->unit ?: '-';
            $unitCost = (float) $sku->cost_price;
            $vatRate = (float) ($line['vat_rate'] ?? 0);
            $lineSubtotal = $quantity * $unitPrice;
            $vatAmount = round($lineSubtotal * $vatRate / 100, 2);
            $lineTotal = $lineSubtotal + $vatAmount;
            $lineCost = $quantity * $unitCost;

            $quotation->items()->create([
                'sku_id' => $sku->id,
                'sku_code' => $sku->sku_code,
                'list_unit_price' => $listPrice,
                'name' => $lineName,
                'unit' => $lineUnit,
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
            'status' => $quotation->workflow_version == 2 ? 'draft' : ($margin < 15 ? 'pending_approval' : 'ready'),
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

    private function money(string $value): string
    {
        return bcadd($value, bccomp($value, '0', 6) >= 0 ? '0.005' : '-0.005', 2);
    }

    private function calculateDraft(Quotation $quote, User $actor, array $items): array
    {
        $quote->items()->delete();
        $subtotal = $net = $tax = $cost = '0.00';
        $factor = bcdiv(bcsub('100', (string) $quote->discount_percent, 4), '100', 6);
        foreach ($items as $line) {
            $sku = Sku::where('tenant_id', $actor->tenant_id)->where('status', 'active')->findOrFail($line['sku_id']);
            $qty = (string) $line['quantity'];
            $list = $this->money((string) ($line['unit_price'] ?? $sku->sale_price));
            $price = $this->money(bcmul($list, $factor, 6));
            $base = $this->money(bcmul($qty, $price, 6));
            $vat = (string) ($line['vat_rate'] ?? 0);
            $taxLine = $this->money(bcdiv(bcmul($base, $vat, 6), '100', 6));
            $costLine = $this->money(bcmul($qty, (string) $sku->cost_price, 6));
            abort_if(bccomp(bcadd($base, $taxLine, 2), '9999999999999999.99', 2) > 0 || bccomp($costLine, '9999999999999999.99', 2) > 0, 422, 'Giá trị hàng hóa vượt giới hạn lưu trữ.');
            $quote->items()->create(['sku_id' => $sku->id, 'sku_code' => $sku->sku_code, 'name' => $sku->name, 'unit' => $sku->unit ?: '-',
                'quantity' => $qty, 'list_unit_price' => $list, 'unit_price' => $price, 'unit_cost' => $sku->cost_price,
                'line_subtotal' => $base, 'vat_rate' => $vat, 'vat_amount' => $taxLine, 'line_total' => bcadd($base, $taxLine, 2), 'line_cost' => $costLine]);
            $subtotal = bcadd($subtotal, $this->money(bcmul($qty, $list, 6)), 2);
            $net = bcadd($net, $base, 2); $tax = bcadd($tax, $taxLine, 2); $cost = bcadd($cost, $costLine, 2);
        }
        $total = bcadd($net, $tax, 2);
        abort_if(bccomp($total, '9999999999999999.99', 2) > 0 || bccomp($subtotal, '9999999999999999.99', 2) > 0, 422, 'Tổng giá trị báo giá vượt giới hạn lưu trữ.');
        $margin = bccomp($net, '0', 2) > 0 ? $this->money(bcmul(bcdiv(bcsub($net, $cost, 2), $net, 8), '100', 6)) : '0.00';
        $quote->update(['subtotal_amount' => $subtotal, 'discount_amount' => bcsub($subtotal, $net, 2), 'tax_amount' => $tax,
            'total_amount' => $total, 'total_cost' => $cost, 'margin_percent' => $margin, 'status' => 'draft']);
        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $total, 'cost' => $cost, 'margin' => (float) $margin];
    }
}
