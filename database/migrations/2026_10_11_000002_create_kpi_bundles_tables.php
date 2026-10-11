<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code', 'version']);
        });
        Schema::create('kpi_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kpi_definition_id')->constrained()->restrictOnDelete();
            $table->string('metric');
            $table->decimal('target_value', 18, 2);
            $table->decimal('weight', 8, 2);
            $table->string('direction')->default('increase');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['kpi_bundle_id', 'kpi_definition_id']);
        });
        Schema::table('kpi_assignments', function (Blueprint $table) {
            $table->foreignId('kpi_bundle_id')->nullable()->after('kpi_definition_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kpi_assignments', fn (Blueprint $table) => $table->dropConstrainedForeignId('kpi_bundle_id'));
        Schema::dropIfExists('kpi_bundle_items');
        Schema::dropIfExists('kpi_bundles');
    }
};
