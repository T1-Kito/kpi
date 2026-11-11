<?php
namespace Tests\Feature;

use App\Models\{Tenant,User};
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Hash,Storage};
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use RefreshDatabase;
    private function account(): User {
        $tenant=Tenant::create(['code'=>'PROFILE','name'=>'Profile','status'=>'active']);
        return User::create(['tenant_id'=>$tenant->id,'name'=>'Nhân viên','email'=>'profile@example.test','password'=>'Original123','is_active'=>true]);
    }
    public function test_ordinary_user_changes_own_password_and_old_sessions_are_revoked(): void {
        $user=$this->account();$token=app(JwtTokenService::class)->issue($user->fresh());
        $this->withToken($token)->postJson('/api/v1/me/password',['current_password'=>'wrong','password'=>'Newpass123','password_confirmation'=>'Newpass123'])->assertUnprocessable();
        $this->assertTrue(Hash::check('Original123',$user->fresh()->password));
        $this->withToken($token)->postJson('/api/v1/me/password',['current_password'=>'Original123','password'=>'short','password_confirmation'=>'short'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/me/password',['current_password'=>'Original123','password'=>'Newpass123','password_confirmation'=>'Newpass123'])->assertOk();
        $this->assertTrue(Hash::check('Newpass123',$user->fresh()->password));
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseHas('users',['id'=>$user->id,'email'=>'profile@example.test']);
    }
    public function test_avatar_is_private_and_invalid_upload_is_rejected(): void {
        Storage::fake('local');$user=$this->account();$token=app(JwtTokenService::class)->issue($user->fresh());
        $this->withToken($token)->post('/api/v1/me/avatar',['avatar'=>UploadedFile::fake()->create('bad.svg',2,'image/svg+xml')],['Accept'=>'application/json'])->assertUnprocessable();
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $file=UploadedFile::fake()->createWithContent('avatar.png',$png);
        $this->withToken($token)->post('/api/v1/me/avatar',['avatar'=>$file],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.avatar','data:image/png;base64,'.base64_encode($png));
        Storage::disk('local')->assertExists($user->fresh()->avatar_path);
        $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.has_avatar',true);
        $this->assertArrayNotHasKey('avatar_path',$user->fresh()->toArray());
    }
}

