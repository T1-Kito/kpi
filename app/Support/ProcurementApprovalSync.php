<?php

namespace App\Support;

use App\Models\SupplierQuotation;
use App\Models\User;

class ProcurementApprovalSync
{
    // Caller holds a transaction; preserve all flows that already have decisions.
    public function sync(User $actor, array $config): int
    {
        $quotes = SupplierQuotation::where('tenant_id', $actor->tenant_id)
            ->where('status', 'draft')->whereDoesntHave('purchaseOrders')
            ->orderBy('id')->lockForUpdate()->get();
        $count = 0;
        foreach ($quotes as $quote) {
            if (collect($quote->approval_flow ?? [])->contains(fn ($step) => !empty($step['decided_at']))) continue;
            $flow = null;
            if (!empty($config['enabled'])) {
                $rule = collect($config['rules'] ?? [])->filter(fn ($rule) => (float) $rule['minimum_amount'] <= (float) $quote->total_amount)->sortByDesc('minimum_amount')->first();
                abort_unless($rule, 422, 'Chưa có luồng duyệt phù hợp với báo giá '.$quote->code);
                $users = User::where('tenant_id', $actor->tenant_id)->where('is_active', true)->whereIn('id', $rule['approvers'])->get()->keyBy('id');
                $flow = collect($rule['approvers'])->map(function ($id, $index) use ($users, $rule) {
                    abort_unless(isset($users[$id]) && $users[$id]->hasPermission('procurement.po.approve'), 422, 'Nhân sự thiếu quyền duyệt mua hàng.');
                    return ['user_id' => (int) $id, 'name' => $users[$id]->name, 'final' => $index === count($rule['approvers']) - 1, 'decided_at' => null];
                })->all();
            }
            if ($quote->approval_flow === $flow) continue;
            $old = $quote->approval_flow;
            $quote->update(['approval_flow' => $flow]);
            app(AuditLogger::class)->record('supplier_quotation', $quote->id, 'sync_procurement_approval', $actor, ['approval_flow' => $old], ['approval_flow' => $flow]);
            $count++;
        }
        return $count;
    }
}
