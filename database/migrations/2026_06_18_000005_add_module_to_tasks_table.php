<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'module')) {
                $table->string('module')->default('general')->after('description')->index();
            }
        });

        DB::table('tasks')->whereNull('module')->orWhere('module', '')->update(['module' => 'general']);

        DB::table('tasks')->whereIn('task_type', ['lead_follow_up', 'quotation_follow_up', 'margin_approval', 'sales_order_confirmation', 'delivery_confirmation'])
            ->update(['module' => 'sales']);
        DB::table('tasks')->whereIn('task_type', ['warehouse_issue', 'goods_receipt', 'inventory_check'])
            ->update(['module' => 'inventory']);
        DB::table('tasks')->whereIn('task_type', ['purchase_request', 'purchase_order_follow_up', 'supplier_follow_up'])
            ->update(['module' => 'procurement']);
        DB::table('tasks')->whereIn('task_type', ['invoice_issue', 'payment_follow_up', 'receivable_follow_up'])
            ->update(['module' => 'finance']);
        DB::table('tasks')->whereIn('task_type', ['kpi_review', 'alert_resolution'])
            ->update(['module' => 'kpi']);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'module')) {
                $table->dropColumn('module');
            }
        });
    }
};
