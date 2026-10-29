<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('quotations', 'payment_plan')) {
            Schema::table('quotations', fn(Blueprint $table) => $table->text('payment_plan')->nullable());
        }
    }
    public function down(): void
    {
        Schema::table('quotations', fn(Blueprint $table) => $table->dropColumn('payment_plan'));
    }
};
