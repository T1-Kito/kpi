<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('import_record_links',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('tenant_id');$t->unsignedBigInteger('source_id');
            $t->string('catalog',30);$t->string('external_id',100);$t->unsignedBigInteger('record_id');
            $t->unsignedInteger('source_revision');$t->string('source_hash',64);$t->string('crm_hash',64);$t->timestamps();
            $t->unique(['tenant_id','source_id','catalog','external_id'],'import_links_identity_unique');
            $t->unique(['tenant_id','catalog','record_id'],'import_links_record_unique');
        });
    }
    public function down(): void {throw new RuntimeException('Không tự xóa liên kết nguồn nhập. Cần phục hồi được kiểm duyệt.');}
};
