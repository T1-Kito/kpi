<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $deleteIds = DB::table('permissions')->whereIn('code', [
            'sales.quotation.delete', 'sales.order.delete',
        ])->pluck('id');
        $adminRoleIds = DB::table('roles')->where('code', 'ROLE-ADMIN')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $deleteIds)
            ->whereNotIn('role_id', $adminRoleIds)->delete();
    }

    public function down(): void
    {
        // Do not grant destructive permissions automatically on rollback.
    }
};
