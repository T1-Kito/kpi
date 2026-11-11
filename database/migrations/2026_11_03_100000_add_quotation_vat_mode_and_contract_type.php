<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::table('quotations',fn(Blueprint $t)=>$t->boolean('prices_include_vat')->default(false));Schema::table('contracts',fn(Blueprint $t)=>$t->string('contract_type',100)->nullable());}
 public function down():void{Schema::table('contracts',fn(Blueprint $t)=>$t->dropColumn('contract_type'));Schema::table('quotations',fn(Blueprint $t)=>$t->dropColumn('prices_include_vat'));}
};
