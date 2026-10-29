<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void { \App\Support\QuotationApprovalPermission::install(DB::connection()); }
    public function down(): void {}
};
