<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('import_sources',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('tenant_id');$t->string('code',50);$t->string('name',150);$t->boolean('is_active')->default(true);$t->timestamps();
            $t->unique(['tenant_id','code']);
        });
        Schema::create('import_source_users',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('source_id');$t->unsignedBigInteger('user_id');$t->unique(['source_id','user_id']);
        });
        Schema::create('import_batches',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('tenant_id');$t->unsignedBigInteger('source_id');$t->unsignedBigInteger('user_id');
            $t->string('catalog',30);$t->string('file_hash',64);$t->string('original_name',200);$t->string('extension',5);$t->string('status',20);
            $t->text('result_json')->nullable();$t->longText('encrypted_payload')->nullable();$t->timestamp('expires_at');$t->timestamp('committed_at')->nullable();$t->timestamps();
            $t->index(['tenant_id','user_id','id']);$t->index(['tenant_id','source_id','catalog','file_hash']);
        });
    }
    public function down(): void { throw new RuntimeException('Không tự xóa lịch sử nhập. Cần quy trình phục hồi được kiểm duyệt.'); }
};
