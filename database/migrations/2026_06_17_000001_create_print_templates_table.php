<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('module', 50);
            $table->longText('content_html')->nullable();
            $table->json('merge_fields')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 30)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'module', 'status']);
            $table->index(['tenant_id', 'module', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_templates');
    }
};
