<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('service_tickets', function(Blueprint $table) {
            $table->string('related_document',120)->nullable();
            $table->string('customer_confirmation',30)->nullable();
        });
    }
    public function down(): void {
        throw new RuntimeException('Không tự xóa lịch sử phản hồi khách hàng. Cần kế hoạch phục hồi đã duyệt.');
    }
};
