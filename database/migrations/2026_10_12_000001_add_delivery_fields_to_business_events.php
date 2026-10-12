<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_events', function (Blueprint $table) {
            $table->timestamp('last_attempted_at')->nullable()->after('retry_count');
            $table->timestamp('next_attempt_at')->nullable()->after('last_attempted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('business_events', function (Blueprint $table) {
            $table->dropIndex(['next_attempt_at']);
            $table->dropColumn(['last_attempted_at', 'next_attempt_at']);
        });
    }
};
