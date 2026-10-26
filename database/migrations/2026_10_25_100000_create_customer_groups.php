<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_groups', function ($t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['tenant_id', 'parent_id']);
        });
        Schema::table('customers', fn ($t) => $t->unsignedBigInteger('customer_group_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('customers', fn ($t) => $t->dropColumn('customer_group_id'));
        Schema::dropIfExists('customer_groups');
    }
};
