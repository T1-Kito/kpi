<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'sales.report.export' => 'Xuất Excel báo cáo bán hàng',
            'finance.report.export' => 'Xuất Excel báo cáo tài chính và công nợ',
            'procurement.report.export' => 'Xuất Excel báo cáo mua hàng',
            'inventory.report.export' => 'Xuất Excel báo cáo kho',
            'kpi.report.export' => 'Xuất Excel báo cáo KPI nhân viên',
        ] as $code => $name) {
            DB::table('permissions')->updateOrInsert(['code' => $code], ['name' => $name, 'updated_at' => now(), 'created_at' => now()]);
            $id = DB::table('permissions')->where('code', $code)->value('id');
            // Preserve existing role assignments. Only the system administrator role gets defaults.
            foreach (DB::table('roles')->where('code', 'ROLE-ADMIN')->pluck('id') as $role) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $role, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', ['sales.report.export', 'finance.report.export', 'procurement.report.export', 'inventory.report.export', 'kpi.report.export'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
