<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;

class OperationalApproval
{
    public const TYPES = ['purchase_order' => 'procurement.po.approve', 'goods_receipt' => 'inventory.receipt.confirm'];

    public function flow(int $tenantId, string $type): ?array
    {
        $ids = Tenant::findOrFail($tenantId)->ui_settings['operational_approval'][$type]['approvers'] ?? [];
        if (!$ids) return null;
        $users = User::where('tenant_id', $tenantId)->where('is_active', true)->whereIn('id', $ids)->get()->keyBy('id');
        return collect($ids)->map(function ($id, $index) use ($users, $ids, $type) {
            abort_unless(isset($users[$id]) && $users[$id]->hasPermission(self::TYPES[$type]), 422, 'Người duyệt không còn hoạt động hoặc thiếu quyền. Vui lòng cập nhật thiết lập.');
            return ['user_id' => (int) $id, 'name' => $users[$id]->name, 'final' => $index === count($ids) - 1, 'decided_at' => null];
        })->all();
    }

    public function decide(array $flow, User $actor): array
    {
        $index = collect($flow)->search(fn ($step) => (int) $step['user_id'] === (int) $actor->id);
        abort_if($index === false, 403, 'Bạn không thuộc danh sách duyệt phiếu này.');
        abort_if(!empty($flow[$index]['decided_at']), 422, 'Bạn đã duyệt phiếu này.');
        abort_if($index > 0 && empty($flow[$index - 1]['decided_at']), 422, 'Chưa hoàn tất cấp duyệt trước.');
        $flow[$index]['decided_at'] = now()->toIso8601String();
        return $flow;
    }
}
