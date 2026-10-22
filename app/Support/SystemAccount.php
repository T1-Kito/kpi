<?php
namespace App\Support;

use App\Models\{User,Role};

/** Reserved role identity is provisioned only by an operator, never the role API. */
final class SystemAccount
{
    public const ROLE='ROLE-SPADMIN';
    public const PERMISSION='dashboard.chairman.view';

    public static function protect(User $target): void
    {
        abort_if($target->isSpAdmin(),403,'Tài khoản Chủ tịch được bảo vệ. Không chỉnh sửa qua quản lý người dùng.');
    }

    public static function assertOrdinaryRoles(array $ids,int $tenant): void
    {
        abort_if(Role::where('tenant_id',$tenant)->whereIn('id',$ids)->where('code',self::ROLE)->exists(),403,'Không thể cấp SPAdmin qua vai trò.');
    }
}
