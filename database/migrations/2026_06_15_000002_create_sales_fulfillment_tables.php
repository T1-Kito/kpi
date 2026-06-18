<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('delivery_status')->default('not_ready')->index();
            $table->string('payment_status')->default('unpaid')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_issue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_name');
            $table->string('recipient_phone')->nullable();
            $table->string('delivery_address');
            $table->timestamp('delivered_at');
            $table->text('proof_note')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('delivered')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'sales_order_id', 'status']);
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable()->index();
            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('balance_amount', 15, 2)->default(0);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('issued')->index();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'sales_order_id', 'status']);
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamp('paid_at');
            $table->string('payment_method')->default('bank_transfer');
            $table->string('reference_no')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'sales_invoice_id', 'paid_at']);
        });

        DB::table('sales_orders')
            ->where('status', 'completed')
            ->update([
                'status' => 'awaiting_delivery',
                'delivery_status' => 'pending',
                'payment_status' => 'unpaid',
                'completed_at' => null,
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('deliveries');

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_status', 'payment_status', 'delivered_at', 'completed_at']);
        });
    }
};
