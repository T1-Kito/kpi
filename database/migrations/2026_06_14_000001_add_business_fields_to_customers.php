<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('tax_code', 30)->nullable()->after('name');
            $table->string('address')->nullable()->after('email');
            $table->unique(['tenant_id', 'tax_code']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'tax_code']);
            $table->dropColumn(['tax_code', 'address']);
        });
    }
};
