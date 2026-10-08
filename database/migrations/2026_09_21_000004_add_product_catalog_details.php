<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->string('name'); $table->string('status')->default('active'); $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });
        Schema::create('product_brands', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->string('name'); $table->string('status')->default('active'); $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('parent_id')->constrained('product_categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->after('category_id')->constrained('product_brands')->nullOnDelete();
            $table->string('image_path')->nullable()->after('name');
            $table->text('description')->nullable()->after('image_path');
            $table->json('technical_specs')->nullable()->after('description');
        });
    }
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) { $table->dropConstrainedForeignId('category_id'); $table->dropConstrainedForeignId('brand_id'); $table->dropColumn(['image_path', 'description', 'technical_specs']); });
        Schema::dropIfExists('product_brands'); Schema::dropIfExists('product_categories');
    }
};
