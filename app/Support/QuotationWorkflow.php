<?php

namespace App\Support;

use App\Models\{Approval, Customer, PrintTemplate, Quotation, QuotationIssue, User, VkNotification};
use App\Services\Print\QuotationDocxMergeService;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;

class QuotationWorkflow
{
    public function __construct(private readonly AuditLogger $audit) {}

    private function locked(Quotation $quote, User $actor): Quotation
    {
        return Quotation::where('tenant_id', $actor->tenant_id)->whereKey($quote->id)->lockForUpdate()->firstOrFail();
    }

    private function record(Quotation $quote, User $actor, string $action, array $data = []): void
    {
        $this->audit->record('quotation', $quote->id, $action, $actor, null, $data);
    }

    public function submit(Quotation $quote, User $actor): Quotation
    {
        return DB::transaction(function () use ($quote, $actor) {
            $quote = $this->locked($quote, $actor);
            abort_unless($quote->workflow_version == 2 && $quote->status === 'draft', 422, 'Chỉ gửi duyệt báo giá nháp theo luồng mới.');
            abort_unless($quote->valid_until && ! $quote->valid_until->endOfDay()->isPast(), 422, 'Cần ngày hiệu lực hợp lệ trước khi gửi duyệt.');
            $quote->load('priceBook');
            abort_if($quote->priceBook && (! $quote->priceBook->is_active || ($quote->priceBook->effective_from && $quote->priceBook->effective_from->startOfDay()->isFuture()) || ($quote->priceBook->effective_to && $quote->priceBook->effective_to->endOfDay()->isPast())), 422, 'Chính sách giá không còn hiệu lực. Hãy cập nhật nháp.');
            abort_if($quote->items()->whereHas('sku', fn ($sku) => $sku->where('status', '!=', 'active')->orWhere('tenant_id', '!=', $actor->tenant_id))->exists(), 422, 'Có hàng hóa không còn hoạt động. Hãy cập nhật nháp.');
            abort_unless($quote->total_amount > 0 && $quote->items()->exists(), 422, 'Báo giá phải có hàng hóa và giá trị lớn hơn 0.');
            abort_if($quote->items()->where('unit_price', '<=', 0)->exists(), 422, 'Cần nhập đơn giá cho tất cả hàng hóa.');
            $policy = $actor->tenant->fresh()->ui_settings['sales_quotation_approval'] ?? [];
            $ids = $policy['approvers'] ?? array_filter([$actor->manager_id]);
            $reasons = [];
            if ((float) $quote->margin_percent < (float) ($policy['minimum_margin'] ?? 15)) $reasons[] = 'Biên lợi nhuận thấp';
            if ((float) $quote->discount_percent > (float) ($policy['maximum_discount'] ?? 0)) $reasons[] = 'Chiết khấu vượt ngưỡng';
            if (! empty($policy['amount_threshold']) && $quote->total_amount >= $policy['amount_threshold']) $reasons[] = 'Giá trị vượt ngưỡng';
            if ($quote->items()->where('unit_cost', '<=', 0)->exists()) $reasons[] = 'Chưa có giá vốn đầy đủ';
            $quote->load('customer', 'paymentTerm');
            $standardTerms = $quote->customer->payment_terms ?: $quote->paymentTerm?->name;
            if ($quote->payment_terms && $quote->payment_terms !== $standardTerms) $reasons[] = 'Điều khoản thanh toán khác mặc định';
            if ($quote->delivery_terms) $reasons[] = 'Điều khoản giao hàng riêng';
            if ($reasons) $ids = array_merge($ids, $policy['exception_approvers'] ?? []);
            $ids = array_values(array_unique(array_map('intval', $ids)));
            abort_unless($ids, 422, 'Chưa thiết lập người duyệt báo giá bán hàng. Vui lòng cấu hình trong Thiết lập.');
            $steps = [];
            foreach ($ids as $id) {
                $user = User::where('tenant_id', $actor->tenant_id)->where('is_active', true)->find($id);
                abort_unless($user && $user->hasPermission('sales.margin.approve'), 422, 'Người duyệt chưa hoạt động hoặc thiếu quyền duyệt báo giá.');
                $steps[] = ['user_id' => $id, 'name' => $user->name, 'status' => 'waiting'];
            }
            $steps[0]['status'] = 'pending';
            $approval = Approval::create(['tenant_id' => $actor->tenant_id, 'source_type' => 'Quotation', 'source_id' => $quote->id, 'approver_id' => $ids[0], 'status' => 'pending', 'reason' => implode('; ', $reasons) ?: 'Duyệt báo giá trước phát hành']);
            $steps[0]['approval_id'] = $approval->id;
            $quote->load('customer', 'priceBook', 'paymentTerm');
            abort_unless($quote->customer->status === 'active' && ! $quote->customer->merged_into_id, 422, 'Khách hàng không còn hoạt động.');
            $customerSnapshot = $quote->customer->toArray();
            $contact = $quote->contact_id ? \App\Models\CustomerContact::where('customer_id', $quote->customer_id)->find($quote->contact_id) : null;
            if ($contact) $customerSnapshot = [...$customerSnapshot, 'contact_name' => $contact->name, 'phone' => $contact->phone ?: $quote->customer->phone, 'email' => $contact->email ?: $quote->customer->email];
            $quote->update(['status' => 'pending_approval', 'submitted_at' => now(), 'approval_steps' => $steps,
                'approval_policy_snapshot' => [...$policy, 'reasons' => $reasons],
                'document_snapshot' => ['customer' => $customerSnapshot, 'contact' => $contact?->toArray(), 'price_book' => $quote->priceBook?->toArray(), 'payment_term' => $quote->paymentTerm?->toArray()]]);
            $this->notify($quote, $ids[0]);
            $this->record($quote, $actor, 'submit_quotation', ['steps' => $steps, 'reasons' => $reasons]);
            return $quote->refresh();
        });
    }

    private function notify(Quotation $quote, int $id): void
    {
        VkNotification::create(['tenant_id' => $quote->tenant_id, 'recipient_id' => $id, 'title' => 'Báo giá cần duyệt', 'message' => $quote->code, 'source_type' => 'Quotation', 'source_id' => $quote->id, 'action_url' => '/quotations?open='.$quote->id, 'status' => 'unread']);
    }

    public function decide(Quotation $quote, User $actor, string $decision, ?string $reason): Quotation
    {
        return DB::transaction(function () use ($quote, $actor, $decision, $reason) {
            $quote = $this->locked($quote, $actor);
            abort_unless($quote->status === 'pending_approval', 422, 'Báo giá không còn chờ duyệt.');
            abort_if($decision === 'approved' && (! $quote->valid_until || $quote->valid_until->endOfDay()->isPast()), 422, 'Báo giá đã hết hiệu lực. Cần tạo phiên bản mới.');
            abort_unless(in_array($decision, ['approved', 'rejected', 'change_requested'], true), 422);
            abort_if($decision !== 'approved' && ! trim($reason ?? ''), 422, 'Cần ghi lý do từ chối hoặc yêu cầu sửa.');
            $steps = $quote->approval_steps ?? [];
            $index = collect($steps)->search(fn ($step) => $step['status'] === 'pending');
            abort_if($index === false, 422, 'Không có bước duyệt đang chờ.');
            abort_unless($steps[$index]['user_id'] === $actor->id && $actor->is_active && $actor->hasPermission('sales.margin.approve'), 403, 'Chưa đến lượt duyệt của bạn.');
            Approval::whereKey($steps[$index]['approval_id'])->where('status', 'pending')->update(['status' => $decision, 'reason' => $reason, 'decided_at' => now()]);
            $steps[$index] = [...$steps[$index], 'status' => $decision, 'reason' => $reason, 'decided_at' => now()->toIso8601String()];
            $status = $decision;
            if ($decision === 'approved' && isset($steps[$index + 1])) {
                $next = $index + 1;
                $user = User::where('tenant_id', $actor->tenant_id)->where('is_active', true)->find($steps[$next]['user_id']);
                abort_unless($user && $user->hasPermission('sales.margin.approve'), 422, 'Người duyệt tiếp theo không còn hợp lệ.');
                $approval = Approval::create(['tenant_id' => $actor->tenant_id, 'source_type' => 'Quotation', 'source_id' => $quote->id, 'approver_id' => $user->id, 'status' => 'pending']);
                $steps[$next]['status'] = 'pending';
                $steps[$next]['approval_id'] = $approval->id;
                $this->notify($quote, $user->id);
                $status = 'pending_approval';
            } elseif ($decision !== 'approved') {
                foreach ($steps as &$step) if ($step['status'] === 'waiting') $step['status'] = 'cancelled';
                unset($step);
            }
            $quote->update(['status' => $status, 'approval_steps' => $steps]);
            $this->record($quote, $actor, $decision.'_quotation', ['reason' => $reason, 'step' => $index + 1, 'creator_approved' => (int) $quote->created_by === (int) $actor->id]);
            return $quote->refresh();
        });
    }

    public function revise(Quotation $quote, User $actor): Quotation
    {
        return DB::transaction(function () use ($quote, $actor) {
            $rootId = $quote->revision_root_id ?: $quote->id;
            Quotation::where('tenant_id', $actor->tenant_id)->whereKey($rootId)->lockForUpdate()->firstOrFail();
            $quote = $this->locked($quote, $actor);
            abort_if($quote->status === 'superseded' || $quote->status === 'draft', 422, 'Phiên bản này đã thay thế hoặc đang là nháp.');
            $family = Quotation::where('tenant_id', $actor->tenant_id)->where(fn ($q) => $q->whereKey($rootId)->orWhere('revision_root_id', $rootId));
            abort_if((clone $family)->whereHas('salesOrders')->exists(), 422, 'Báo giá đã có đơn hàng. Không thay thế phiên bản đã chốt.');
            $revision = (clone $family)->max('revision_number') + 1;
            $quote->load('items');
            $copy = app(QuotationService::class)->create($actor, [
                'customer_id' => $quote->customer_id, 'lead_id' => $quote->lead_id, 'deal_id' => $quote->deal_id,
                'price_book_id' => $quote->price_book_id, 'payment_term_id' => $quote->payment_term_id,
                'discount_percent' => $quote->discount_percent, 'valid_until' => $quote->valid_until?->toDateString(),
                'payment_terms' => $quote->payment_terms, 'delivery_terms' => $quote->delivery_terms, 'note' => $quote->note,
                'items' => $quote->items->map(fn ($item) => ['sku_id' => $item->sku_id, 'quantity' => $item->quantity, 'unit_price' => $item->list_unit_price ?? $item->unit_price, 'vat_rate' => $item->vat_rate])->all()]);
            $copy->update(['revision_root_id' => $rootId, 'revision_number' => $revision, 'sales_owner_id' => $quote->sales_owner_id]);
            Approval::where('tenant_id', $actor->tenant_id)->where('source_type', 'Quotation')->where('source_id', $quote->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now(), 'reason' => 'Đã tạo phiên bản mới']);
            $steps = $quote->approval_steps ?? [];
            foreach ($steps as &$step) if (in_array($step['status'], ['pending', 'waiting'], true)) $step['status'] = 'cancelled';
            unset($step);
            $quote->update(['status' => 'superseded', 'approval_steps' => $steps]);
            $this->record($quote, $actor, 'revise_quotation', ['new_id' => $copy->id, 'revision' => $revision]);
            return $copy->refresh();
        });
    }

    public function issue(Quotation $quote, User $actor, array $data): Quotation
    {
        $stored = null;
        try {
            return DB::transaction(function () use ($quote, $actor, $data, &$stored) {
                $quote = $this->locked($quote, $actor);
                abort_unless($quote->workflow_version == 2 && $quote->status === 'approved', 422, 'Chỉ phát hành báo giá đã duyệt đủ.');
                abort_unless($quote->valid_until && ! $quote->valid_until->endOfDay()->isPast(), 422, 'Báo giá đã hết hiệu lực. Hãy tạo phiên bản mới.');
                $template = PrintTemplate::where('tenant_id', $actor->tenant_id)->where('module', 'quotation')->where('status', 'active')->orderByDesc('is_default')->latest('id')->first();
                abort_unless($template && $template->file_path, 422, 'Cần mẫu Word báo giá hợp lệ trước khi phát hành.');
                $quote->setRelation('customer', new Customer($quote->document_snapshot['customer']));
                $path = app(QuotationDocxMergeService::class)->merge($quote, $template);
                try {
                    $bytes = file_get_contents($path);
                    abort_if($bytes === false, 422, 'Không đọc được file báo giá.');
                    $stored = 'quotation-issues/'.$actor->tenant_id.'/'.Str::uuid().'.docx';
                    abort_unless(Storage::disk('local')->put($stored, $bytes), 500, 'Không lưu được file phát hành.');
                    QuotationIssue::create(['tenant_id' => $actor->tenant_id, 'quotation_id' => $quote->id, 'issued_by' => $actor->id,
                        'recipient' => $data['recipient'], 'channel' => $data['channel'], 'delivery_evidence' => $data['delivery_evidence'],
                        'file_path' => $stored, 'file_hash' => hash('sha256', $bytes), 'template_name' => $template->name, 'issued_at' => now()]);
                } finally { if (is_file($path)) unlink($path); }
                $quote->update(['status' => 'issued', 'issued_at' => now()]);
                $this->record($quote, $actor, 'issue_quotation', $data);
                return $quote->refresh();
            });
        } catch (\Throwable $error) {
            if ($stored) Storage::disk('local')->delete($stored);
            throw $error;
        }
    }

    public function customerDecision(Quotation $quote, User $actor, array $data): Quotation
    {
        return DB::transaction(function () use ($quote, $actor, $data) {
            $quote = $this->locked($quote, $actor);
            abort_unless($quote->status === 'issued', 422, 'Chỉ ghi nhận phản hồi cho báo giá đã phát hành.');
            abort_unless($quote->valid_until && ! $quote->valid_until->endOfDay()->isPast(), 422, 'Báo giá hết hiệu lực. Cần phiên bản mới trước khi khách đồng ý.');
            $issue = QuotationIssue::where('quotation_id', $quote->id)->firstOrFail();
            $issue->update(['customer_decision' => $data['decision'], 'customer_evidence' => $data['evidence'], 'customer_decided_at' => now()]);
            $quote->update(['status' => $data['decision'] === 'accepted' ? 'accepted' : 'customer_rejected', 'accepted_at' => $data['decision'] === 'accepted' ? now() : null]);
            $this->record($quote, $actor, 'customer_quotation_response', $data);
            return $quote->refresh();
        });
    }
}
