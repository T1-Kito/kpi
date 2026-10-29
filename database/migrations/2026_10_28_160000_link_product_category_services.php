<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {Schema::table('product_categories',fn($t)=>$t->unsignedBigInteger('service_interest_id')->nullable());}
    public function down():void {Schema::table('product_categories',fn($t)=>$t->dropColumn('service_interest_id'));}
};
