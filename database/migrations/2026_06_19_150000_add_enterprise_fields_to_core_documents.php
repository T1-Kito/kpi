<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('legal_representative')->nullable()->after('billing_address');
            $table->string('representative_position')->nullable()->after('legal_representative');
            $table->string('payment_terms')->nullable()->after('representative_position');
            $table->string('bank_name')->nullable()->after('payment_terms');
            $table->string('bank_account_no')->nullable()->after('bank_name');
            $table->string('bank_account_name')->nullable()->after('bank_account_no');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('tax_code', 30)->nullable()->after('name');
            $table->string('address')->nullable()->after('email');
            $table->string('contact_name')->nullable()->after('address');
            $table->string('payment_terms')->nullable()->after('terms');
            $table->string('bank_name')->nullable()->after('payment_terms');
            $table->string('bank_account_no')->nullable()->after('bank_name');
            $table->string('bank_account_name')->nullable()->after('bank_account_no');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->date('valid_until')->nullable()->after('margin_percent');
            $table->string('payment_terms')->nullable()->after('valid_until');
            $table->string('delivery_terms')->nullable()->after('payment_terms');
            $table->text('note')->nullable()->after('delivery_terms');
        });

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->string('tax_invoice_symbol', 50)->nullable()->after('code');
            $table->string('tax_invoice_no', 50)->nullable()->after('tax_invoice_symbol');
            $table->timestamp('issued_at')->nullable()->after('invoice_date');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('payment_terms')->nullable()->after('expected_delivery_date');
            $table->string('delivery_terms')->nullable()->after('payment_terms');
            $table->string('warranty_terms')->nullable()->after('delivery_terms');
            $table->decimal('shipping_fee', 15, 2)->default(0)->after('warranty_terms');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['payment_terms', 'delivery_terms', 'warranty_terms', 'shipping_fee']);
        });

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropColumn(['tax_invoice_symbol', 'tax_invoice_no', 'issued_at']);
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn(['valid_until', 'payment_terms', 'delivery_terms', 'note']);
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn([
                'tax_code',
                'address',
                'contact_name',
                'payment_terms',
                'bank_name',
                'bank_account_no',
                'bank_account_name',
            ]);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'legal_representative',
                'representative_position',
                'payment_terms',
                'bank_name',
                'bank_account_no',
                'bank_account_name',
            ]);
        });
    }
};
