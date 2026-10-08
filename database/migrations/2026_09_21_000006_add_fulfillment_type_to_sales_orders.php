<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('sales_orders', function (Blueprint $table) { $table->string('fulfillment_type')->default('from_stock')->after('payment_terms')->index(); $table->string('supplier_delivery_note')->nullable()->after('fulfillment_type'); }); } public function down(): void { Schema::table('sales_orders', function (Blueprint $table) { $table->dropColumn(['fulfillment_type','supplier_delivery_note']); }); } };
