<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('source_type')->nullable();
            $table->string('formula')->nullable();
            $table->string('unit')->nullable();
            $table->string('target_direction')->default('increase');
            $table->decimal('weight', 8, 2)->default(1);
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('kpi_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kpi_definition_id')->constrained('kpi_definitions')->cascadeOnDelete();
            $table->string('period_type')->default('month')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_value', 15, 2)->default(0);
            $table->decimal('actual_value', 15, 2)->default(0);
            $table->decimal('score', 8, 2)->default(0);
            $table->string('status')->default('draft')->index();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'kpi_definition_id', 'period_type', 'period_start'], 'kpi_target_unique');
        });

        Schema::create('kpi_score_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date')->index();
            $table->string('period_type')->default('day')->index();
            $table->decimal('overall_score', 8, 2)->default(0);
            $table->json('metrics');
            $table->string('status')->default('draft')->index();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'snapshot_date', 'period_type'], 'kpi_snapshot_unique');
        });

        Schema::create('kpi_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('kpi_definition_id')->nullable()->constrained('kpi_definitions')->nullOnDelete();
            $table->foreignId('kpi_target_id')->nullable()->constrained('kpi_targets')->nullOnDelete();
            $table->foreignId('kpi_score_snapshot_id')->nullable()->constrained('kpi_score_snapshots')->nullOnDelete();
            $table->string('period_type')->default('month')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->text('reason');
            $table->string('evidence_url')->nullable();
            $table->string('status')->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_exceptions');
        Schema::dropIfExists('kpi_score_snapshots');
        Schema::dropIfExists('kpi_targets');
        Schema::dropIfExists('kpi_definitions');
    }
};
