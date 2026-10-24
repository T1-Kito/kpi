<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallAdminTest extends TestCase
{
    use RefreshDatabase;
    public function test_clean_install_creates_one_admin_without_demo_and_cannot_overwrite_it(): void
    {
        config(['app.key'=>'base64:'.base64_encode(random_bytes(32))]);
        $password='Test1234';
        $this->artisan('crm:install-admin --company=UAT --email=uat@example.test')
            ->expectsQuestion('Mật khẩu Chủ tịch (ít nhất 8 ký tự)',$password)
            ->expectsQuestion('Confirm password',$password)
            ->expectsQuestion('Email Admin thường','operator@example.test')
            ->expectsQuestion('Mật khẩu Admin thường (ít nhất 8 ký tự)','User1234')
            ->expectsQuestion('Nhập lại mật khẩu Admin thường','User1234')
            ->expectsConfirmation('Initialize a NEW installation on database '.config('database.default').' / '.config('database.connections.'.config('database.default').'.database').'?', 'yes')->assertSuccessful();
        $this->assertDatabaseCount('users',2);
        $this->assertDatabaseCount('tenants',1);
        $this->assertDatabaseCount('customers',0);
        $this->assertDatabaseCount('leads',0);
        $this->assertDatabaseCount('skus',0);
        $user=User::firstOrFail();
        $this->assertTrue($user->hasPermission('user.manage'));
        $this->assertTrue($user->hasPermission('master.cost.view'));
        $this->assertTrue($user->hasPermission('dashboard.chairman.view'));
        $operator=User::where('email','operator@example.test')->firstOrFail();
        $this->assertFalse($operator->isSpAdmin());
        $this->assertFalse($operator->hasPermission('dashboard.chairman.view'));
        $this->assertTrue($operator->hasPermission('user.manage'));
        $this->postJson('/api/v1/auth/login',['email'=>$operator->email,'password'=>'User1234'])->assertOk();
        $this->postJson('/api/v1/auth/login',['email'=>$user->email,'password'=>$password])->assertOk();
        $this->artisan('crm:install-admin --company=Changed --email=other@example.test')->assertFailed();
        $this->assertDatabaseCount('users',2);
        $this->assertDatabaseHas('tenants',['name'=>'UAT']);
    }

    public function test_mismatched_password_does_not_create_company_or_account(): void
    {
        $this->artisan('crm:install-admin --company=UAT --email=uat@example.test')
            ->expectsQuestion('Mật khẩu Chủ tịch (ít nhất 8 ký tự)','Unique-UAT-Password-123')
            ->expectsQuestion('Confirm password','different-password')->assertFailed();
        $this->assertDatabaseCount('users',0);
        $this->assertDatabaseCount('tenants',0);
    }
    public function test_seven_character_password_is_rejected_without_creating_accounts():void
    {
        $this->artisan('crm:install-admin --company=UAT --email=uat@example.test')
            ->expectsQuestion('Mật khẩu Chủ tịch (ít nhất 8 ký tự)','Test123')->assertFailed();
        $this->assertDatabaseCount('users',0);$this->assertDatabaseCount('tenants',0);
    }
}
