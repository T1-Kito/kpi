<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->string('tax_code')->nullable()->after('customer_id');
            $table->string('customer_name')->nullable()->after('tax_code');
            $table->string('customer_phone')->nullable()->after('customer_name');
            $table->string('customer_email')->nullable()->after('customer_phone');
            $table->text('customer_address')->nullable()->after('customer_email');
            $table->date('purchase_date')->nullable()->after('product_name');
            $table->date('warranty_start_at')->nullable()->after('purchase_date');
            $table->unsignedSmallInteger('warranty_months')->default(12)->after('warranty_start_at');
            $table->index(['tenant_id', 'serial_number']);
            $table->index(['tenant_id', 'warranty_end_at']);
        });
    }
    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'serial_number']); $table->dropIndex(['tenant_id', 'warranty_end_at']);
            $table->dropColumn(['tax_code', 'customer_name', 'customer_phone', 'customer_email', 'customer_address', 'purchase_date', 'warranty_start_at', 'warranty_months']);
        });
    }
};
