<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { \App\Support\LeadWorkflowSchema::up(DB::connection()); }
    public function down(): void {
        foreach (['leads','customers','customer_contacts'] as $table) Schema::table($table,fn($t)=>$t->dropColumn('search_text'));
        Schema::table('leads',fn($t)=>$t->dropColumn(['needs','region','expected_service_date','guest_count','interested_services','notes','handover_to','handover_from','handover_status','workflow_history','merged_into_id']));
    }
};
