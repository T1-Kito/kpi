<?php

namespace App\Console\Commands;

use App\Models\{Tenant,User,Role,Permission};
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Hash,Route,Validator};

class InstallAdmin extends Command
{
    protected $signature='crm:install-admin {--company= : Company name} {--email= : Admin email}';
    protected $description='Create the first company/admin only on an unused installation; no demo data';

    public function handle(): int
    {
        if (User::exists() || Tenant::exists()) {
            $this->error('Installation already initialized. No existing account or password was changed.');
            return self::FAILURE;
        }
        if (!filled(config('app.key'))) {$this->error('Generate APP_KEY for the new installation first.');return self::FAILURE;}
        $data=['company'=>$this->option('company') ?: $this->ask('Company name'),'email'=>$this->option('email') ?: $this->ask('Admin email'),'password'=>$this->secret('Mật khẩu Chủ tịch (ít nhất 8 ký tự)')];
        $validator=Validator::make($data,['company'=>['required','string','max:255'],'email'=>['required','email','max:255'],'password'=>['required','string','min:8','max:128']]);
        if ($validator->fails()) {$this->error('Invalid company, email or password length. Nothing was created.');return self::FAILURE;}
        if ($data['password']!==$this->secret('Confirm password')) {$this->error('Passwords do not match.');return self::FAILURE;}
        $data['operator_email']=$this->ask('Email Admin thường');
        $data['operator_password']=$this->secret('Mật khẩu Admin thường (ít nhất 8 ký tự)');
        if(Validator::make($data,['operator_email'=>'required|email|different:email','operator_password'=>'required|string|min:8|max:128'])->fails() || $data['operator_password']!==$this->secret('Nhập lại mật khẩu Admin thường')) {
            $this->error('Thông tin Admin thường không hợp lệ. Chưa tạo tài khoản.');return self::FAILURE;
        }
        if (!$this->confirm('Initialize a NEW installation on database '.config('database.default').' / '.DB::connection()->getDatabaseName().'?',false)) return self::FAILURE;
        // Include all route-protected capabilities plus non-route display/data capabilities.
        $codes=['dashboard.chairman.view','master.cost.view'];
        foreach (Route::getRoutes() as $route) foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware,'permission:')) $codes=array_merge($codes,explode('|',substr($middleware,11)));
        }
        DB::transaction(function () use($codes,$data) {
            // The installation command must run exclusively before publishing the site.
            if (User::exists() || Tenant::exists()) throw new \RuntimeException('Installation was initialized by another process.');
            foreach(array_unique($codes) as $code) Permission::firstOrCreate(['code'=>$code],['name'=>$code]);
            $tenant=Tenant::create(['code'=>'CRM','name'=>$data['company'],'status'=>'active']);
            $role=Role::create(['tenant_id'=>$tenant->id,'code'=>'ROLE-SPADMIN','name'=>'Chủ tịch / Super Admin','data_scope'=>'company']);
            $role->permissions()->sync(Permission::pluck('id')->all());
            $user=User::create(['tenant_id'=>$tenant->id,'name'=>'Quản trị hệ thống','email'=>$data['email'],'password'=>Hash::make($data['password']),'is_active'=>true]);
            $user->roles()->sync([$role->id]);
            \App\Support\InitialAdministrator::create($tenant->id,$data['operator_email'],$data['operator_password']);
            app(AuditLogger::class)->record('installation',$tenant->id,'initialize_installation',$user,null,['company'=>$tenant->name,'admin_id'=>$user->id]);
        });
        $this->info('Đã tạo doanh nghiệp, một tài khoản Chủ tịch và một Admin thường. Không tạo dữ liệu mẫu.');
        return self::SUCCESS;
    }
}
