<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('supplier_quotations', fn (Blueprint $table) => $table->json('approval_flow')->nullable()); }
    public function down(): void { Schema::table('supplier_quotations', fn (Blueprint $table) => $table->dropColumn('approval_flow')); }
};
