<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('product_categories',fn($t)=>$t->unsignedBigInteger('parent_id')->nullable()->index()); }
    public function down(): void { Schema::table('product_categories',fn($t)=>$t->dropColumn('parent_id')); }
};
