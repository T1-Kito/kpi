<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(['code'=>'dashboard.chairman.view'], ['name'=>'Giao diện điều hành Chủ tịch', 'created_at'=>now(), 'updated_at'=>now()]);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code','dashboard.chairman.view')->value('id');
        DB::table('role_permissions')->where('permission_id',$id)->delete();
        DB::table('permissions')->where('id',$id)->delete();
    }
};
