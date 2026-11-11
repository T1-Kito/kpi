<?php
namespace App\Support;

use Illuminate\Database\Connection;

final class LeadWorkflowSchema
{
    public static function up(Connection $db): void
    {
        $db->getSchemaBuilder()->table('leads', function ($t) {
            $t->text('needs')->nullable(); $t->string('region')->nullable();
            $t->date('expected_service_date')->nullable(); $t->unsignedInteger('guest_count')->nullable();
            $t->text('interested_services')->nullable(); $t->text('notes')->nullable();
            $t->unsignedBigInteger('handover_to')->nullable(); $t->unsignedBigInteger('handover_from')->nullable();
            $t->string('handover_status', 20)->nullable(); $t->text('workflow_history')->nullable();
            $t->unsignedBigInteger('merged_into_id')->nullable();
        });
        foreach (['leads','customers','customer_contacts'] as $table) {
            $db->getSchemaBuilder()->table($table, fn ($t) => $t->text('search_text')->nullable());
            $db->table($table)->orderBy('id')->chunkById(300, function ($rows) use ($db, $table) {
                foreach ($rows as $row) $db->table($table)->where('id',$row->id)->update(['search_text'=>VietnameseSearch::text((array)$row)]);
            });
        }
    }
}
