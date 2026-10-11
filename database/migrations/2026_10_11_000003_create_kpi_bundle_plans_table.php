<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_bundle_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kpi_bundle_id')->constrained()->cascadeOnDelete();
            $table->string('audience_type');
            $table->unsignedBigInteger('audience_id')->default(0);
            $table->string('period_type');
            $table->string('assignment_status')->default('active');
            $table->date('starts_on');
            $table->string('status')->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['tenant_id', 'kpi_bundle_id', 'audience_type', 'audience_id', 'period_type'], 'kpi_bundle_plan_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_bundle_plans');
    }
};
