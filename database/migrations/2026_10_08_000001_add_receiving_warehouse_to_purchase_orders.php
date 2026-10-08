<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('receiving_warehouse_id')->nullable()->after('supplier_id')->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('receiving_warehouse_id'));
    }
};
