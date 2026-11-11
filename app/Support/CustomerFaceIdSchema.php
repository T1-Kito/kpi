<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class CustomerFaceIdSchema
{
    public static function up(Builder $schema): void
    {
        $schema->create('customer_faceid_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('source_key', 64);
            $table->unsignedBigInteger('guest_id');
            $table->string('guest_name', 200);
            $table->timestamps();
            $table->unique(['tenant_id', 'source_key', 'guest_id'], 'faceid_guest_source_unique');
            $table->index(['tenant_id', 'customer_id', 'source_key'], 'faceid_customer_source_index');
        });
    }
}
