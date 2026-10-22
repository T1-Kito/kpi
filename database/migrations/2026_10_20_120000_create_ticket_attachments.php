<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void { Schema::create('ticket_attachments',fn(Blueprint $t)=>\App\Support\AdditiveDatabaseUpgrade::evidenceSchema($t)); }
    public function down():void { throw new RuntimeException('Không tự xóa bảng ảnh minh chứng khách hàng.'); }
};
