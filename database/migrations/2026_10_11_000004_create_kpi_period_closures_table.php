<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_period_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kpi_bundle_id')->constrained()->restrictOnDelete();
            $table->string('audience_type');
            $table->unsignedBigInteger('audience_id')->default(0);
            $table->string('period_type');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('revision')->default(1);
            $table->string('status')->default('closed');
            $table->json('member_ids');
            $table->json('snapshot');
            $table->foreignId('closed_by')->constrained('users');
            $table->timestamp('closed_at');
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'kpi_bundle_id', 'audience_type', 'audience_id', 'period_type', 'period_start', 'revision'], 'kpi_period_closure_revision_unique');
            $table->index(['tenant_id', 'period_start', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_period_closures');
    }
};
