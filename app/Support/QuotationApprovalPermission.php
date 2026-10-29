<?php
namespace App\Support;
use Illuminate\Database\Connection;
final class QuotationApprovalPermission
{
    public static function install(Connection $db): void
    {
        $code = SalesApprovalAccess::ANY;
        $db->table('permissions')->updateOrInsert(['code'=>$code], ['name'=>'Duyệt mọi báo giá / duyệt thay các cấp']);
        $id = $db->table('permissions')->where('code',$code)->value('id');
        foreach ($db->table('roles')->whereIn('code',['ROLE-ADMIN','ROLE-SPADMIN'])->pluck('id') as $role) {
            $db->table('role_permissions')->updateOrInsert(['role_id'=>$role,'permission_id'=>$id], []);
        }
    }
}
