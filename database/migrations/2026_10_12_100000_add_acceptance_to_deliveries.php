<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->insertGetId([
            'code' => 'sales.delivery.accept',
            'name' => 'Xác nhận nghiệm thu giao hàng',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (DB::table('roles')->whereIn('code', ['ROLE-ADMIN', 'ROLE-DIR', 'ROLE-SALES'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('acceptance_status')->default('pending')->index()->after('status');
            $table->timestamp('accepted_at')->nullable()->after('acceptance_status');
            $table->string('accepted_by_name')->nullable()->after('accepted_at');
            $table->string('accepted_by_title')->nullable()->after('accepted_by_name');
            $table->boolean('quantity_accepted')->default(true)->after('accepted_by_title');
            $table->boolean('condition_accepted')->default(true)->after('quantity_accepted');
            $table->boolean('documents_accepted')->default(true)->after('condition_accepted');
            $table->text('acceptance_note')->nullable()->after('accepted_by_title');
            $table->text('discrepancy_note')->nullable()->after('acceptance_note');
            $table->foreignId('acceptance_confirmed_by')->nullable()->after('discrepancy_note')->constrained('users')->nullOnDelete();
        });

        DB::table('deliveries')->where('status', 'delivered')->update([
            'acceptance_status' => 'accepted',
            'accepted_at' => DB::raw('delivered_at'),
            'accepted_by_name' => DB::raw('recipient_name'),
        ]);
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex(['acceptance_status']);
            $table->dropForeign(['acceptance_confirmed_by']);
            $table->dropColumn([
                'acceptance_status', 'accepted_at', 'accepted_by_name', 'accepted_by_title',
                'quantity_accepted', 'condition_accepted', 'documents_accepted',
                'acceptance_note', 'discrepancy_note', 'acceptance_confirmed_by',
            ]);
        });

        $permissionId = DB::table('permissions')->where('code', 'sales.delivery.accept')->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
