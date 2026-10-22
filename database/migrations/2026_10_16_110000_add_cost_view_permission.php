<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(['code'=>'master.cost.view'], ['name'=>'Xem giá vốn và biên lợi nhuận', 'created_at'=>now(), 'updated_at'=>now()]);
        $permission = DB::table('permissions')->where('code','master.cost.view')->value('id');
        foreach (DB::table('roles')->whereIn('code',['ROLE-ADMIN','ROLE-SPADMIN'])->pluck('id') as $role) {
            DB::table('role_permissions')->updateOrInsert(['role_id'=>$role,'permission_id'=>$permission], []);
        }
    }
    public function down(): void
    {
        $id = DB::table('permissions')->where('code','master.cost.view')->value('id');
        DB::table('role_permissions')->where('permission_id',$id)->delete();
        DB::table('permissions')->where('id',$id)->delete();
    }
};
