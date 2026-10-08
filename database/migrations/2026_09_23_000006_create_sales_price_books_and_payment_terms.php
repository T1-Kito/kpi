<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_price_books', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->string('code'); $table->string('name');
            $table->decimal('discount_percent', 5, 2)->default(0); $table->date('effective_from')->nullable(); $table->date('effective_to')->nullable();
            $table->boolean('is_default')->default(false); $table->boolean('is_active')->default(true); $table->timestamps(); $table->unique(['tenant_id', 'code']);
        });
        Schema::create('sales_payment_terms', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->string('code'); $table->string('name');
            $table->unsignedSmallInteger('due_days')->default(0); $table->boolean('is_default')->default(false); $table->boolean('is_active')->default(true); $table->timestamps(); $table->unique(['tenant_id', 'code']);
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('price_book_id')->nullable()->after('deal_id')->constrained('sales_price_books')->nullOnDelete();
            $table->foreignId('payment_term_id')->nullable()->after('price_book_id')->constrained('sales_payment_terms')->nullOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) { $table->dropConstrainedForeignId('payment_term_id'); $table->dropConstrainedForeignId('price_book_id'); });
        Schema::dropIfExists('sales_payment_terms'); Schema::dropIfExists('sales_price_books');
    }
};
