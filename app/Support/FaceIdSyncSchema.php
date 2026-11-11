<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class FaceIdSyncSchema
{
    public static function up(Builder $schema): void
    {
        $schema->create('faceid_source_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('source_key', 64);
            $table->string('resource', 16);
            $table->string('external_id', 128);
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->string('label', 255)->nullable();
            $table->string('pin', 100)->nullable();
            $table->date('arrives_on')->nullable();
            $table->date('leaves_on')->nullable();
            $table->text('payload');
            $table->timestamps();
            $table->unique(['tenant_id', 'source_key', 'resource', 'external_id'], 'faceid_source_records_identity');
        });
        $schema->create('faceid_sync_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('source_key', 64);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->text('counts')->nullable();
            $table->string('status', 16);
            $table->unique(['tenant_id', 'source_key'], 'faceid_sync_states_source');
        });
    }
}
