<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void { \App\Support\InstallationDefaultAccounts::provision(DB::connection()); }
    public function down(): void {}
};
