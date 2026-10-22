<?php
namespace App\Support;

use App\Models\{User,Role,Permission};
use Illuminate\Support\Facades\{Hash,Validator};

final class InitialAdministrator
{
    // Called inside the clean-install transaction only; never on software update.
    public static function create(int $tenantId,string $email,string $password): User
    {
        Validator::make(compact('email','password'),[
            'email'=>'required|email|max:255|unique:users,email',
            'password'=>'required|string|min:12|max:128',
        ])->validate();
        $role=Role::firstOrCreate(['tenant_id'=>$tenantId,'code'=>'ROLE-ADMIN'],['name'=>'Quản trị hệ thống','data_scope'=>'company']);
        $role->permissions()->sync(Permission::where('code','!=',SystemAccount::PERMISSION)->pluck('id'));
        $user=User::create(['tenant_id'=>$tenantId,'name'=>'Quản trị viên','email'=>$email,'password'=>Hash::make($password),'is_active'=>true]);
        $user->roles()->sync([$role->id]);
        return $user;
    }
}
