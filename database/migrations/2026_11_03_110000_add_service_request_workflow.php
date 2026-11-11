<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{\App\Support\ServiceRequestSchema::up(DB::connection());}
 public function down():void{Schema::table('service_tickets',fn($t)=>$t->dropColumn(\App\Support\ServiceRequestSchema::COLUMNS));}
};
