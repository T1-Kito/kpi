<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->after('customer_type')->constrained('customers')->nullOnDelete();
        });
        Schema::create('customer_duplicate_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('first_customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('second_customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('matched_on');
            $table->string('status')->default('open');
            $table->foreignId('retained_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'first_customer_id', 'second_customer_id'], 'customer_duplicate_pair_unique');
            $table->index(['tenant_id', 'status']);
        });

        $permissionId = DB::table('permissions')->insertGetId([
            'code' => 'master.merge', 'name' => 'Xét và gộp khách hàng trùng',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('roles')->where('code', 'ROLE-ADMIN')->pluck('id') as $roleId) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', 'master.merge')->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        Schema::dropIfExists('customer_duplicate_reviews');
        Schema::table('customers', fn (Blueprint $table) => $table->dropConstrainedForeignId('merged_into_id'));
    }
};
