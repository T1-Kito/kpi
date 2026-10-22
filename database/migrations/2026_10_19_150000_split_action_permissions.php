<?php

use App\Support\ActionPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            foreach (ActionPermissions::SPLITS as $legacy=>$permissions) {
                $roles=DB::table('role_permissions')->join('permissions','permissions.id','=','role_permissions.permission_id')
                    ->where('permissions.code',$legacy)->pluck('role_id');
                foreach ($permissions as $code=>$name) {
                    DB::table('permissions')->updateOrInsert(['code'=>$code],['name'=>$name,'created_at'=>now(),'updated_at'=>now()]);
                    $id=DB::table('permissions')->where('code',$code)->value('id');
                    // Preserve existing effective actions; administrators may now revoke each independently.
                    foreach ($roles as $role) DB::table('role_permissions')->updateOrInsert(['role_id'=>$role,'permission_id'=>$id], []);
                }
            }
        });
    }

    public function down(): void
    {
        // Do not erase deliberate role edits or silently recombine separated duties.
        throw new RuntimeException('Permission split requires a reviewed restore plan; automatic downgrade is disabled.');
    }
};
