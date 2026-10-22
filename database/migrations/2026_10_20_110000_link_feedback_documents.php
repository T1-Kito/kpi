<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void { Schema::table('service_tickets',function(Blueprint $t){ $t->string('document_type',20)->nullable(); $t->unsignedBigInteger('document_id')->nullable(); }); }
    public function down():void { throw new RuntimeException('Không tự xóa liên kết chứng từ phản hồi.'); }
};
