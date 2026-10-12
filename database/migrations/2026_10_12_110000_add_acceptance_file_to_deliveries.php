<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('acceptance_file_path')->nullable()->after('acceptance_note');
            $table->string('acceptance_file_name')->nullable()->after('acceptance_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['acceptance_file_path', 'acceptance_file_name']);
        });
    }
};
