<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kpi_definition_id')->constrained()->cascadeOnDelete();
            $table->string('audience_type');
            $table->unsignedBigInteger('audience_id')->default(0);
            $table->string('period_type');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('metric');
            $table->decimal('target_value', 18, 2);
            $table->decimal('weight', 8, 2)->default(1);
            $table->string('direction')->default('increase');
            $table->string('status')->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('locked_at')->nullable();
            $table->json('locked_results')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'kpi_definition_id', 'audience_type', 'audience_id', 'period_type', 'period_start'], 'kpi_assignment_unique');
            $table->index(['tenant_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_assignments');
    }
};
