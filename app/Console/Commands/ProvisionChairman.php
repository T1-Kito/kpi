<?php
namespace App\Console\Commands;

use App\Models\{Tenant,User,Role,Permission};
use App\Support\{SystemAccount,AuditLogger};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Hash,Validator};

class ProvisionChairman extends Command
{
    protected $signature='crm:provision-chairman {tenant : Existing tenant ID} {--email= : Private account email}';
    protected $description='Operator-only creation of the reserved Chairman account; never resets an existing account';

    public function handle(): int
    {
        $tenant=Tenant::whereKey($this->argument('tenant'))->where('status','active')->first();
        if(!$tenant || User::whereHas('roles',fn($q)=>$q->where('code',SystemAccount::ROLE))->exists()) {
            $this->error('Doanh nghiệp không hợp lệ hoặc tài khoản Chủ tịch đã tồn tại. Không thay đổi tài khoản cũ.');return self::FAILURE;
        }
        $data=['email'=>$this->option('email'),'password'=>$this->secret('Mật khẩu riêng cho Chủ tịch (ít nhất 12 ký tự)')];
        if(Validator::make($data,['email'=>'required|email|max:255|unique:users,email','password'=>'required|string|min:12|max:128'])->fails()) {
            $this->error('Email hoặc mật khẩu không hợp lệ.');return self::FAILURE;
        }
        if($data['password']!==$this->secret('Nhập lại mật khẩu'))return self::FAILURE;
        if(!$this->confirm('Tạo tài khoản Chủ tịch đăng nhập admin cho '.$tenant->name.' trên database '.DB::connection()->getDatabaseName().'?',false))return self::FAILURE;
        DB::transaction(function()use($tenant,$data){
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_if(User::whereHas('roles',fn($q)=>$q->where('code',SystemAccount::ROLE))->exists(),409);
            Permission::firstOrCreate(['code'=>SystemAccount::PERMISSION],['name'=>'Tổng quan riêng Chủ tịch']);
            $role=Role::firstOrCreate(['tenant_id'=>$tenant->id,'code'=>SystemAccount::ROLE],['name'=>'Chủ tịch / SPAdmin','data_scope'=>'company']);
            $role->update(['data_scope'=>'company']);
            $role->permissions()->sync(Permission::pluck('id')->all());
            $user=User::create(['tenant_id'=>$tenant->id,'name'=>'Chủ tịch','email'=>$data['email'],'password'=>Hash::make($data['password']),'is_active'=>true]);
            $user->roles()->sync([$role->id]);
            app(AuditLogger::class)->record('user',$user->id,'provision_chairman',$user,null,['login'=>'admin','tenant_id'=>$tenant->id]);
        });
        $this->info('Đã tạo Chủ tịch. Đăng nhập: admin. Không in hoặc lưu mật khẩu vào nhật ký.');return self::SUCCESS;
    }
}
