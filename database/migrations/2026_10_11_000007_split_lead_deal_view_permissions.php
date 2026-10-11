<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['sales.lead.view' => 'Xem khách hàng tiềm năng', 'sales.deal.view' => 'Xem cơ hội bán hàng'] as $code => $name) {
            DB::table('permissions')->updateOrInsert(['code' => $code], ['name' => $name, 'updated_at' => $now, 'created_at' => $now]);
        }
        foreach (['sales.lead' => 'sales.lead.manage', 'sales.deal' => 'sales.deal.manage'] as $prefix => $legacy) {
            $viewId = DB::table('permissions')->where('code', $prefix.'.view')->value('id');
            $legacyId = DB::table('permissions')->where('code', $legacy)->value('id');
            if (! $viewId || ! $legacyId) continue;
            foreach (DB::table('role_permissions')->where('permission_id', $legacyId)->pluck('role_id') as $roleId) {
                DB::table('role_permissions')->updateOrInsert(['permission_id' => $viewId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', ['sales.lead.view', 'sales.deal.view'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
