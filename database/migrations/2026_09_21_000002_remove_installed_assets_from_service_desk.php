<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->string('serial_number')->nullable()->after('customer_id');
            $table->string('product_name')->nullable()->after('serial_number');
            $table->dropConstrainedForeignId('installed_asset_id');
        });
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->string('serial_number')->nullable()->after('customer_id');
            $table->string('product_name')->nullable()->after('serial_number');
            $table->date('warranty_end_at')->nullable()->after('product_name');
            $table->dropConstrainedForeignId('installed_asset_id');
        });
        Schema::dropIfExists('installed_assets');
    }

    public function down(): void
    {
        Schema::create('installed_assets', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('asset_code'); $table->string('serial_number')->nullable(); $table->string('name'); $table->timestamps();
        });
        Schema::table('service_tickets', function (Blueprint $table) { $table->foreignId('installed_asset_id')->nullable()->constrained()->nullOnDelete(); $table->dropColumn(['serial_number', 'product_name']); });
        Schema::table('warranty_claims', function (Blueprint $table) { $table->foreignId('installed_asset_id')->nullable()->constrained()->nullOnDelete(); $table->dropColumn(['serial_number', 'product_name', 'warranty_end_at']); });
    }
};
