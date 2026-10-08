<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->string('code'); $table->string('name');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete(); $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete(); $table->decimal('total_amount', 15, 2)->default(0); $table->date('effective_date')->nullable(); $table->date('expiry_date')->nullable();
            $table->string('status')->default('draft')->index(); $table->text('note')->nullable(); $table->timestamp('accepted_at')->nullable(); $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });
        Schema::create('contract_milestones', function (Blueprint $table) {
            $table->id(); $table->foreignId('contract_id')->constrained()->cascadeOnDelete(); $table->string('name'); $table->decimal('amount', 15, 2); $table->date('due_date')->nullable(); $table->string('status')->default('pending')->index(); $table->date('accepted_date')->nullable(); $table->text('note')->nullable(); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('contract_milestones'); Schema::dropIfExists('contracts'); }
};
