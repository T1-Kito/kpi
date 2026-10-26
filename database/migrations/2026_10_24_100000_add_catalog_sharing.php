<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void { \App\Support\CatalogSharingSchema::up(DB::connection()); }
    public function down(): void {
        foreach (['customers', 'skus'] as $table) Schema::table($table, function ($t) use ($table) {
            $t->dropIndex($table.'_catalog_visibility');
            $t->dropColumn(['created_by', 'is_shared']);
        });
    }
};
