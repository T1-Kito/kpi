<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('supplier_quotations', function (Blueprint $table) {
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->string('document_mime')->nullable();
        });
    }
    public function down(): void {
        Schema::table('supplier_quotations', fn (Blueprint $table) => $table->dropColumn(['document_path', 'document_name', 'document_mime']));
    }
};
