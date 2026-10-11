<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const PERMISSIONS = [
        'procurement.pr.view' => ['Xem yêu cầu mua', 'procurement.pr.approve'],
        'procurement.pr.create' => ['Lập yêu cầu mua', 'procurement.pr.approve'],
        'procurement.sq.view' => ['Xem báo giá nhà cung cấp', 'procurement.po.approve'],
        'procurement.sq.manage' => ['Quản lý báo giá nhà cung cấp', 'procurement.po.approve'],
        'procurement.po.view' => ['Xem đơn mua', 'procurement.po.approve'],
        'procurement.po.create' => ['Lập đơn mua', 'procurement.po.approve'],
        'inventory.receipt.view' => ['Xem phiếu nhập kho', 'inventory.receipt.confirm'],
        'inventory.receipt.create' => ['Lập phiếu nhập kho', 'inventory.receipt.confirm'],
        'inventory.issue.view' => ['Xem phiếu xuất kho', 'inventory.issue.confirm'],
        'inventory.issue.create' => ['Lập phiếu xuất kho', 'inventory.issue.confirm'],
        'inventory.stocktake.view' => ['Xem phiếu kiểm kê', 'inventory.issue.confirm'],
        'inventory.stocktake.create' => ['Lập phiếu kiểm kê', 'inventory.issue.confirm'],
        'inventory.stocktake.confirm' => ['Xác nhận kiểm kê', 'inventory.issue.confirm'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $code => [$name, $legacyCode]) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');
            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'code' => $code,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $legacyId = DB::table('permissions')->where('code', $legacyCode)->value('id');
            if (! $legacyId) continue;

            foreach (DB::table('role_permissions')->where('permission_id', $legacyId)->pluck('role_id') as $roleId) {
                DB::table('role_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PERMISSIONS) as $code) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');
            if (! $permissionId) continue;
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
