<?php

namespace App\Support;

use App\Models\{Quotation, User, Tenant};
use Carbon\CarbonImmutable;

class SalesApprovalAccess
{
    public const ANY = 'sales.quotation.approve_any';
    public function canApproveAny(User $actor): bool
    {
        return $actor->is_active && $actor->hasPermission(self::ANY);
    }

    public function pool(int $tenantId): array
    {
        return User::where('tenant_id', $tenantId)->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('tenant_id', $tenantId)->whereHas('permissions', fn ($p) => $p->where('code', self::ANY)))
            ->orderBy('id')->pluck('id')->all();
    }
    public function principals(User $actor): array
    {
        if (! $actor->is_active || ! $actor->hasPermission('sales.margin.approve')) return [];
        $ids = [(int)$actor->id];
        foreach ($this->delegations($actor->tenant_id) as $rule) {
            if ((int) $rule['to_user_id'] === (int) $actor->id) $ids[] = (int) $rule['from_user_id'];
        }
        return array_values(array_unique($ids));
    }

    public function canApprove(Quotation $quote, User $actor): bool
    {
        if ((int)$quote->tenant_id !== (int)$actor->tenant_id || $quote->status !== 'pending_approval') return false;
        if ($quote->approval_policy_snapshot['separation_of_duties'] ?? false) {
            if ((int) $quote->created_by === (int) $actor->id) return false;
            if (collect($quote->approval_steps ?? [])->contains(fn ($step) => $step['status'] === 'approved' && (int) ($step['decided_by'] ?? $step['user_id']) === (int) $actor->id)) return false;
        }
        $step = collect($quote->approval_steps ?? [])->firstWhere('status', 'pending');
        return $step && ($this->canApproveAny($actor) || in_array((int) $step['user_id'], $this->principals($actor), true));
    }

    public function recipients(int $tenantId, int $originalId): array
    {
        $ids = [$originalId];
        foreach ($this->delegations($tenantId) as $rule) {
            if ((int) $rule['from_user_id'] === $originalId) $ids[] = (int) $rule['to_user_id'];
        }
        return User::where('tenant_id', $tenantId)->where('is_active', true)->whereIn('id', $ids)->get()
            ->filter(fn ($user) => $user->hasPermission('sales.margin.approve'))->pluck('id')->all();
    }

    private function delegations(int $tenantId): array
    {
        $rules = Tenant::findOrFail($tenantId)->ui_settings['sales_quotation_approval']['delegations'] ?? [];
        return array_values(array_filter($rules, function ($rule) {
            try {
                return CarbonImmutable::parse($rule['starts_at'])->lte(now()) && CarbonImmutable::parse($rule['ends_at'])->gt(now());
            } catch (\Throwable) { return false; }
        }));
    }
}
