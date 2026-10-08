<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // Existing documents remain legacy; only new documents use workflow 2.
            $table->unsignedTinyInteger('workflow_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('customer_contacts')->nullOnDelete();
            $table->foreignId('revision_root_id')->nullable()->constrained('quotations')->restrictOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->json('document_snapshot')->nullable();
            $table->json('approval_steps')->nullable();
            $table->json('approval_policy_snapshot')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unique(['revision_root_id', 'revision_number']);
        });
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->string('sku_code')->nullable();
            $table->decimal('list_unit_price', 18, 2)->nullable();
        });
        Schema::create('quotation_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->string('recipient');
            $table->string('channel', 30);
            $table->text('delivery_evidence');
            $table->string('file_path');
            $table->string('file_hash', 64);
            $table->string('template_name');
            $table->string('customer_decision', 30)->nullable();
            $table->text('customer_evidence')->nullable();
            $table->timestamp('customer_decided_at')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_issues');
        Schema::table('quotation_items', fn (Blueprint $table) => $table->dropColumn(['sku_code', 'list_unit_price']));
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropUnique(['revision_root_id', 'revision_number']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('contact_id');
            $table->dropConstrainedForeignId('revision_root_id');
            $table->dropColumn(['workflow_version', 'revision_number', 'discount_percent', 'discount_amount', 'document_snapshot', 'approval_steps', 'approval_policy_snapshot', 'submitted_at', 'issued_at', 'accepted_at']);
        });
    }
};
