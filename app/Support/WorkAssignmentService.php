<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkAssignmentService
{
    public const RULES = [
        'lead' => ['label' => 'Chăm sóc khách hàng tiềm năng', 'permission' => 'sales.lead.manage'],
        'warehouse_issue' => ['label' => 'Xử lý xuất kho', 'permission' => 'inventory.issue.confirm'],
        'goods_receipt' => ['label' => 'Xác nhận nhập kho', 'permission' => 'inventory.receipt.confirm'],
        'purchase_request' => ['label' => 'Xử lý yêu cầu mua', 'permission' => 'procurement.pr.approve'],
    ];

    public function eligibleUsers(int $tenantId, string $rule)
    {
        return User::where('tenant_id', $tenantId)->where('is_active', true)->orderBy('id')->get()
            ->filter(fn (User $user) => $user->hasPermission(self::RULES[$rule]['permission']) && $user->hasPermission('task.view'))->values();
    }

    public function pick(int $tenantId, string $rule): ?int
    {
        if (!isset(self::RULES[$rule])) return null;
        return DB::transaction(function () use ($tenantId, $rule) {
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $settings = $tenant->ui_settings ?? [];
            $config = $settings['work_assignment'] ?? [];
            if (empty($config['enabled'])) return null;
            $pool = array_map('intval', $config['pools'][$rule] ?? []);
            $ids = $this->eligibleUsers($tenantId, $rule)->pluck('id')->filter(fn ($id) => in_array($id, $pool, true))->values();
            if ($ids->isEmpty()) return null;
            // Rotate by the last recipient, so removing a member does not reset the pool.
            $last = (int) ($settings['work_assignment_cursors'][$rule] ?? 0);
            $id = $ids->first(fn ($id) => $id > $last) ?? $ids->first();
            $settings['work_assignment_cursors'][$rule] = $id;
            $tenant->update(['ui_settings' => $settings]);
            return (int) $id;
        });
    }
}
