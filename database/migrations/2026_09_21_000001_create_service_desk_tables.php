<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asset_code');
            $table->string('serial_number')->nullable();
            $table->string('name');
            $table->string('site_name')->nullable();
            $table->date('installed_at')->nullable();
            $table->date('warranty_start_at')->nullable();
            $table->date('warranty_end_at')->nullable();
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'asset_code']);
            $table->unique(['tenant_id', 'serial_number']);
            $table->index(['tenant_id', 'customer_id', 'status']);
            $table->index(['tenant_id', 'warranty_end_at']);
        });

        Schema::create('service_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('installed_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code');
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('source')->default('manual');
            $table->string('category')->default('support');
            $table->string('priority')->default('normal');
            $table->string('status')->default('open');
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_code')->nullable();
            $table->text('resolution_note')->nullable();
            $table->unsignedTinyInteger('satisfaction_score')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'priority']);
            $table->index(['tenant_id', 'assignee_id', 'resolution_due_at']);
        });

        Schema::create('ticket_worklogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->default('note');
            $table->string('visibility')->default('internal');
            $table->text('content');
            $table->unsignedInteger('minutes_spent')->default(0);
            $table->timestamp('worked_at');
            $table->timestamps();
            $table->index(['service_ticket_id', 'worked_at']);
        });

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('installed_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code');
            $table->text('issue_description');
            $table->string('warranty_status');
            $table->string('status')->default('open');
            $table->text('decision_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'warranty_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('ticket_worklogs');
        Schema::dropIfExists('service_tickets');
        Schema::dropIfExists('installed_assets');
    }
};
