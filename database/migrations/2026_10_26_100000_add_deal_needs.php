<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void { Schema::table('deals',fn($t)=>$t->text('needs')->nullable()); }
    public function down():void { Schema::table('deals',fn($t)=>$t->dropColumn('needs')); }
};
