<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_issues', function (Blueprint $table) {
            $table->string('recipient_name')->nullable()->after('warehouse_location_id');
            $table->string('recipient_phone')->nullable()->after('recipient_name');
            $table->text('recipient_address')->nullable()->after('recipient_phone');
            $table->string('delivery_location')->nullable()->after('recipient_address');
            $table->string('issue_reason')->nullable()->after('delivery_location');
            $table->string('source_document')->nullable()->after('issue_reason');
        });
    }

    public function down(): void
    {
        Schema::table('goods_issues', function (Blueprint $table) {
            $table->dropColumn(['recipient_name', 'recipient_phone', 'recipient_address', 'delivery_location', 'issue_reason', 'source_document']);
        });
    }
};
