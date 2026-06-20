<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_orders', 'payment_terms')) {
                $table->string('payment_terms')->nullable()->after('total_amount');
            }
        });

        DB::table('sales_orders')
            ->whereNotNull('quotation_id')
            ->whereNull('payment_terms')
            ->orderBy('id')
            ->get(['id', 'quotation_id'])
            ->each(function ($order): void {
                $paymentTerms = DB::table('quotations')
                    ->where('id', $order->quotation_id)
                    ->value('payment_terms');

                if ($paymentTerms) {
                    DB::table('sales_orders')
                        ->where('id', $order->id)
                        ->update(['payment_terms' => $paymentTerms]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_orders', 'payment_terms')) {
                $table->dropColumn('payment_terms');
            }
        });
    }
};
