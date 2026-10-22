<?php
// Local support inspection: no password hashes, credentials or changes are emitted.
if (PHP_SAPI !== 'cli') exit(1);
$root=$argv[1] ?? '';
if (!is_file($root.'/artisan')) exit(1);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $users=App\Models\User::query()->with('roles:id,code')->orderBy('id')->limit(20)->get();
    echo json_encode($users->map(fn($user)=>[
        'email'=>$user->email,'active'=>(bool)$user->is_active,
        'roles'=>$user->roles->pluck('code')->all(),
        'has_password'=>!empty($user->getRawOriginal('password')),
    ]),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
    if (in_array('--check-demo',$argv,true)) {
        $admin=$users->firstWhere('email','admin@vk-kpi.local');
        echo json_encode(['local_demo_password_matches'=>$admin
            ? Illuminate\Support\Facades\Hash::check('Admin@123',$admin->getRawOriginal('password')) : false]).PHP_EOL;
    }
    if (in_array('--check-session',$argv,true)) {
        $admin=$users->firstWhere('email','admin@vk-kpi.local');
        $verified=false;
        try {
            if ($admin) {
                $tokens=app(App\Support\JwtTokenService::class);
                $tokens->verify($tokens->issue($admin));
                $verified=true;
            }
        } catch (Throwable) { /* Never print tokens or raw SQL errors. */ }
        echo json_encode([
            'token_version_column'=>Illuminate\Support\Facades\Schema::hasColumn('users','token_version'),
            'revocation_table'=>Illuminate\Support\Facades\Schema::hasTable('revoked_access_tokens'),
            'new_session_verifies'=>$verified,
        ]).PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR,"ACCOUNT_READ_ONLY_CHECK_FAILED\n"); exit(1);
}
