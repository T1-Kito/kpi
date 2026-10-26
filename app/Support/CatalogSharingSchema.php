<?php

namespace App\Support;

use Illuminate\Database\Connection;

final class CatalogSharingSchema
{
    public static function up(Connection $db): void
    {
        foreach (['customers', 'skus'] as $table) {
            $db->getSchemaBuilder()->table($table, function ($blueprint) use ($table) {
                $blueprint->unsignedBigInteger('created_by')->nullable();
                $blueprint->boolean('is_shared')->default(false);
                $blueprint->index(['tenant_id', 'created_by', 'is_shared'], $table.'_catalog_visibility');
            });
        }
        // Only recover provenance from creation audit records, never from the current assignee.
        if (!$db->getSchemaBuilder()->hasTable('audit_logs')) return;
        foreach (['customers' => ['customer', ['create_customer', 'create_customer_quick', 'create_customer_from_lead']], 'skus' => ['sku', ['create_sku']]] as $table => [$type, $actions]) {
            $logs = $db->table('audit_logs')->where('entity_type', $type)->whereIn('action', $actions)->whereNotNull('user_id')->orderBy('id');
            $logs->chunkById(200, function ($entries) use ($db, $table) { foreach ($entries as $log) {
                if (!$db->table('users')->where('id', $log->user_id)->where('tenant_id', $log->tenant_id)->exists()) continue;
                $db->table($table)->where('id', $log->entity_id)->where('tenant_id', $log->tenant_id)->whereNull('created_by')->update(['created_by' => $log->user_id]);
            }});
        }
    }
}
