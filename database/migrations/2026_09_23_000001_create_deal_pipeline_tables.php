<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('probability', 5, 2)->default(10);
            $table->date('expected_close_date')->nullable();
            $table->dateTime('next_activity_at')->nullable();
            $table->string('stage')->default('new')->index();
            $table->string('status')->default('open')->index();
            $table->string('lost_reason')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'owner_id', 'status']);
            $table->index(['tenant_id', 'stage', 'expected_close_date']);
        });

        Schema::create('deal_stage_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->index(['deal_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_histories');
        Schema::dropIfExists('deals');
    }
};
