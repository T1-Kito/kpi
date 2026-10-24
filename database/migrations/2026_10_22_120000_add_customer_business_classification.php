<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {Schema::table('customers',function(Blueprint $t){
        $t->string('business_segment',10)->nullable();
        $t->string('customer_group',100)->nullable();
        $t->string('customer_audience',150)->nullable();
    });}
    public function down():void {throw new RuntimeException('Không tự xóa phân nhóm khách hàng đã nhập.');}
};
