<?php
// Explicit operator task: retain hashes, never recover or print passwords.
if(PHP_SAPI!=='cli' || ($argv[1] ?? '')!=='--capture-authorized-single-customer')exit(1);
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$path=base_path('deployment/windows/default-accounts.json');
if(is_file($path))throw new RuntimeException('Profile already exists; refusing to overwrite.');
$accounts=[];
foreach(['admin@vk-kpi.local'=>'ROLE-SPADMIN','hoanthang0307@gmail.com'=>'ROLE-ADMIN'] as $email=>$role) {
    $user=App\Models\User::where('email',$email)->firstOrFail();
    if(!$user->is_active || !$user->roles()->where('code',$role)->where('tenant_id',$user->tenant_id)->exists())throw new RuntimeException('Expected account role missing.');
    $accounts[]=['email'=>$email,'role'=>$role,'password_hash'=>$user->getRawOriginal('password')];
}
file_put_contents($path,json_encode(['format'=>1,'accounts'=>$accounts],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);
echo "Captured authorized two-account installer profile. No passwords or hashes printed.\n";
