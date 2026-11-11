<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::table('service_tickets',fn($t)=>$t->unsignedBigInteger('customer_id')->nullable()->change());}
 public function down():void{if(\Illuminate\Support\Facades\DB::table('service_tickets')->whereNull('customer_id')->exists())throw new \RuntimeException('Không thể bỏ tiếp nhận độc lập khi còn phiếu chưa liên kết khách hàng.');Schema::table('service_tickets',fn($t)=>$t->unsignedBigInteger('customer_id')->nullable(false)->change());}
};
