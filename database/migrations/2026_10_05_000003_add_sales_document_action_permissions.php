<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const PERMISSIONS = [
        'sales.quotation.view' => ['Xem báo giá', 'sales.quotation.create'],
        'sales.quotation.edit' => ['Sửa báo giá', 'sales.quotation.create'],
        'sales.quotation.delete' => ['Xóa báo giá nháp', 'sales.quotation.create'],
        'sales.deal.manage' => ['Quản lý cơ hội bán hàng', 'sales.quotation.create'],
        'sales.contract.manage' => ['Quản lý hợp đồng', 'sales.quotation.create'],
        'sales.order.edit' => ['Sửa đơn hàng nháp', 'sales.order.create'],
        'sales.order.delete' => ['Xóa đơn hàng nháp', 'sales.order.create'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $code => [$name, $previousCode]) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');
            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $previousId = DB::table('permissions')->where('code', str_ends_with($code, '.delete') ? 'role.manage' : $previousCode)->value('id');
            if (! $previousId) continue;
            foreach (DB::table('role_permissions')->where('permission_id', $previousId)->pluck('role_id') as $roleId) {
                if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
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
