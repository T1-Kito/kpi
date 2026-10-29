<?php
namespace App\Support;

use Illuminate\Database\Connection;

final class DashboardPermissions
{
    public static function install(Connection $db): void
    {
        $db->transaction(function () use ($db) {
            foreach ([
                'dashboard.executive.view' => 'Xem tổng quan điều hành',
                'dashboard.manager.view' => 'Xem tổng quan trưởng phòng',
                SystemAccount::PERMISSION => 'Xem tổng quan Chủ tịch',
            ] as $code => $name) {
                if (!$db->table('permissions')->where('code', $code)->exists()) {
                    $db->table('permissions')->insert(['code'=>$code, 'name'=>$name]);
                }
            }
            // Repair only the built-in full-access roles, never ordinary or custom roles.
            $executive = $db->table('permissions')->where('code','dashboard.executive.view')->value('id');
            $chairman = $db->table('permissions')->where('code',SystemAccount::PERMISSION)->value('id');
            foreach ($db->table('roles')->whereIn('code',['ROLE-ADMIN',SystemAccount::ROLE])->get() as $role) {
                $ids = $role->code === SystemAccount::ROLE ? [$executive,$chairman] : [$executive];
                foreach ($ids as $id) {
                    if (!$db->table('role_permissions')->where('role_id',$role->id)->where('permission_id',$id)->exists()) {
                        $db->table('role_permissions')->insert(['role_id'=>$role->id,'permission_id'=>$id]);
                    }
                }
            }
        });
    }
}
