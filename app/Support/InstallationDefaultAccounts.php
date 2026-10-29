<?php

namespace App\Support;

use Illuminate\Database\Connection;
use RuntimeException;

/** Explicit single-customer installer profile, never executed during login. */
final class InstallationDefaultAccounts
{
    public static function loginAlias(string $name): ?array
    {
        $path=base_path('deployment/windows/default-accounts.json');
        if(!is_file($path))return null;
        $data=json_decode(file_get_contents($path),true);
        foreach($data['accounts'] ?? [] as $account) {
            if(!empty($account['login']) && strtolower(trim($name))===strtolower($account['login']))
                return ['email'=>$account['email'],'role'=>$account['role']];
        }
        return null;
    }

    public static function provision(Connection $db, ?string $profile = null): void
    {
        $profile ??= base_path('deployment/windows/default-accounts.json');
        if (!is_file($profile)) return;
        $data=json_decode(file_get_contents($profile),true,512,JSON_THROW_ON_ERROR);
        if (($data['format'] ?? null)!==1 || count($data['accounts'] ?? [])!==2) throw new RuntimeException('DEFAULT_ACCOUNTS_PROFILE_INVALID');
        $tenants=$db->table('tenants')->pluck('id');
        if ($tenants->isEmpty()) return; // Clean installer provisions after creating its company.
        if ($tenants->count()!==1) throw new RuntimeException('DEFAULT_ACCOUNTS_COMPANY_AMBIGUOUS');
        $tenant=(int)$tenants->first();
        $seen=[];
        foreach($data['accounts'] as $account) {
            if (!filter_var($account['email'] ?? '',FILTER_VALIDATE_EMAIL)
                || !in_array($account['role'] ?? '',['ROLE-SPADMIN','ROLE-ADMIN'],true)
                || !in_array(password_get_info($account['password_hash'] ?? '')['algoName'],['bcrypt','argon2id','argon2i'],true)
                || isset($seen[$account['role']])) throw new RuntimeException('DEFAULT_ACCOUNTS_PROFILE_INVALID');
            $seen[$account['role']]=true;
        }
        $db->transaction(function()use($db,$data,$tenant){
            DashboardPermissions::install($db);
            foreach($data['accounts'] as $account) {
                $existing=$db->table('users')->where('email',$account['email'])->first();
                if($existing) {
                    if((int)$existing->tenant_id!==$tenant)throw new RuntimeException('DEFAULT_ACCOUNTS_IDENTITY_CONFLICT');
                    continue; // Never reset, unlock, rename or elevate an existing identity.
                }
                $role=$db->table('roles')->where('tenant_id',$tenant)->where('code',$account['role'])->first();
                $roleId=$role?->id ?? $db->table('roles')->insertGetId(['tenant_id'=>$tenant,'code'=>$account['role'],
                    'name'=>$account['role']==='ROLE-SPADMIN'?'Chủ tịch':'Quản trị hệ thống','data_scope'=>'company','created_at'=>now(),'updated_at'=>now()]);
                $permissions=$db->table('permissions');
                if($account['role']==='ROLE-ADMIN')$permissions->where('code','!=',SystemAccount::PERMISSION);
                foreach($permissions->pluck('id') as $permissionId) {
                    if(!$db->table('role_permissions')->where('role_id',$roleId)->where('permission_id',$permissionId)->exists())
                        $db->table('role_permissions')->insert(['role_id'=>$roleId,'permission_id'=>$permissionId]);
                }
                $id=$db->table('users')->insertGetId(['tenant_id'=>$tenant,'name'=>$account['role']==='ROLE-SPADMIN'?'Chủ tịch':'Quản trị viên',
                    'email'=>$account['email'],'password'=>$account['password_hash'],'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
                $db->table('user_roles')->insert(['user_id'=>$id,'role_id'=>$roleId]);
            }
        });
    }
}
