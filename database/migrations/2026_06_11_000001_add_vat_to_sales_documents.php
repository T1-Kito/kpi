<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->decimal('line_subtotal', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
        });

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('line_subtotal', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
        });

        DB::table('quotations')->update([
            'subtotal_amount' => DB::raw('total_amount'),
        ]);
        DB::table('quotation_items')->update([
            'line_subtotal' => DB::raw('line_total'),
        ]);
        DB::table('sales_orders')->update([
            'subtotal_amount' => DB::raw('total_amount'),
        ]);
        DB::table('sales_order_items')->update([
            'line_subtotal' => DB::raw('line_total'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn(['line_subtotal', 'vat_rate', 'vat_amount']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal_amount', 'tax_amount']);
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropColumn(['line_subtotal', 'vat_rate', 'vat_amount']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['subtotal_amount', 'tax_amount']);
        });
    }
};
