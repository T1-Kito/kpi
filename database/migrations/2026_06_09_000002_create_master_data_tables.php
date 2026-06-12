<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable()->index();
            $table->string('email')->nullable();
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->foreignId('sales_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('position')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('terms')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('supplied_products')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku_code');
            $table->string('name');
            $table->string('barcode')->nullable()->index();
            $table->string('unit')->default('pcs');
            $table->decimal('min_stock', 15, 3)->default(0);
            $table->decimal('max_stock', 15, 3)->default(0);
            $table->decimal('sale_price', 15, 2)->default(0);
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'sku_code']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('address')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['warehouse_id', 'code']);
        });

        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
            $table->string('lot_no')->nullable();
            $table->decimal('on_hand', 15, 3)->default(0);
            $table->decimal('reserved', 15, 3)->default(0);
            $table->decimal('available', 15, 3)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'warehouse_id', 'warehouse_location_id', 'sku_id', 'lot_no'], 'inventory_balance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('warehouse_locations');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('skus');
        Schema::dropIfExists('products');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customers');
    }
};
