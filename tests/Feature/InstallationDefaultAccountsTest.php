<?php

namespace Tests\Feature;

use App\Models\{Tenant,User};
use App\Support\InstallationDefaultAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Hash};
use Tests\TestCase;

class InstallationDefaultAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): string
    {
        $path=tempnam(sys_get_temp_dir(),'vk-default-test-');
        file_put_contents($path,json_encode(['format'=>1,'accounts'=>[
            ['email'=>'chair-test@example.com','role'=>'ROLE-SPADMIN','password_hash'=>Hash::make('ChairTest-123')],
            ['email'=>'admin-test@example.com','role'=>'ROLE-ADMIN','password_hash'=>Hash::make('AdminTest-123')],
        ]]));
        $this->beforeApplicationDestroyed(fn()=>is_file($path)?unlink($path):null);
        return $path;
    }

    public function test_creates_two_accounts_and_reruns_preserve_password_roles_and_locked_state(): void
    {
        $tenant=Tenant::create(['code'=>'ONLY','name'=>'Only customer','status'=>'active']);
        DB::table('permissions')->insertOrIgnore(['code'=>'dashboard.chairman.view','name'=>'Chairman']);
        DB::table('permissions')->insertOrIgnore(['code'=>'sales.quotation.view','name'=>'Quotes']);
        $profile=$this->profile();
        InstallationDefaultAccounts::provision(DB::connection(),$profile);
        $chair=User::where('email','chair-test@example.com')->firstOrFail();
        $admin=User::where('email','admin-test@example.com')->firstOrFail();
        $this->assertSame($tenant->id,$chair->tenant_id);
        $this->assertTrue($chair->isSpAdmin());
        $this->assertFalse($admin->isSpAdmin());
        $this->assertTrue(Hash::check('ChairTest-123',$chair->password));
        $this->assertTrue(Hash::check('AdminTest-123',$admin->password));
        $this->assertFalse($admin->roles->first()->permissions()->where('code','dashboard.chairman.view')->exists());
        $admin->update(['password'=>Hash::make('Changed-password-123'),'is_active'=>false]);
        $hash=$admin->fresh()->getRawOriginal('password');
        InstallationDefaultAccounts::provision(DB::connection(),$profile);
        $this->assertSame(2,User::count());
        $this->assertSame($hash,$admin->fresh()->getRawOriginal('password'));
        $this->assertFalse((bool)$admin->fresh()->is_active);
    }

    public function test_refuses_ambiguous_company_and_does_not_modify_any_existing_accounts(): void
    {
        Tenant::create(['code'=>'A','name'=>'A','status'=>'active']);
        Tenant::create(['code'=>'B','name'=>'B','status'=>'active']);
        try {
            InstallationDefaultAccounts::provision(DB::connection(),$this->profile());
            $this->fail('Must require one company');
        }catch(\RuntimeException $e){$this->assertSame('DEFAULT_ACCOUNTS_COMPANY_AMBIGUOUS',$e->getMessage());}
        $this->assertSame(0,User::count());
    }
}
