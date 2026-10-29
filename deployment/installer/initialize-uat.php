<?php
// CLI only; never put this helper in public/. New SQLite UAT only.
if(PHP_SAPI!=='cli')exit(1);
foreach(['bcmath','mbstring','openssl','pdo_sqlite','fileinfo','zip'] as $extension)if(!extension_loaded($extension))throw new RuntimeException('Missing PHP extension: '.$extension);
$root=__DIR__;require $root.'/vendor/autoload.php';
foreach(['bootstrap/cache','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs'] as $directory){$path=$root.'/'.$directory;if(!is_dir($path)&&!mkdir($path,0700,true))throw new RuntimeException('Cannot create writable directory');if(!is_writable($path))throw new RuntimeException('Directory not writable: '.$directory);}
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!filter_var($input['operator_email']??'',FILTER_VALIDATE_EMAIL)||strlen($input['operator_password']??'')<8||strcasecmp(trim($input['operator_email']??''),trim($input['email']??''))===0)throw new RuntimeException('Cần email riêng và mật khẩu ít nhất 8 ký tự cho Admin thường.');
if(is_file($root.'/.env'))throw new RuntimeException('Existing configuration: will not overwrite.');
if(!filter_var($input['email']??'',FILTER_VALIDATE_EMAIL)||strlen($input['password']??'')<8||trim($input['company']??'')==='')throw new RuntimeException('Invalid company/email/password.');
$database=$root.'/database/uat.sqlite';if(file_exists($database))throw new RuntimeException('Database already exists.');
$key='base64:'.base64_encode(random_bytes(32));
$env="APP_NAME=VK-CRM-UAT\nAPP_ENV=local\nAPP_DEBUG=false\nAPP_KEY=$key\nAPP_URL=http://127.0.0.1:8099\nDB_CONNECTION=sqlite\nDB_DATABASE=\"".str_replace('\\','/',$database)."\"\nSESSION_DRIVER=file\nCACHE_STORE=file\nQUEUE_CONNECTION=sync\nMAIL_MAILER=log\nFILESYSTEM_DISK=local\n";
$file=fopen($root.'/.env','x');fwrite($file,$env);fclose($file);touch($database);
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true])!==0)throw new RuntimeException('Migration failed; retain folder for diagnosis.');
Illuminate\Support\Facades\DB::transaction(function()use($input){
    if(App\Models\User::exists()||App\Models\Tenant::exists())throw new RuntimeException('Already initialized');
    $codes=['dashboard.chairman.view','master.cost.view'];
    foreach(Illuminate\Support\Facades\Route::getRoutes() as $route)foreach($route->gatherMiddleware() as $middleware)if(is_string($middleware)&&str_starts_with($middleware,'permission:'))$codes=array_merge($codes,explode('|',substr($middleware,11)));
    foreach(array_unique($codes)as$code)App\Models\Permission::firstOrCreate(['code'=>$code],['name'=>$code]);
    $tenant=App\Models\Tenant::create(['code'=>'CRM','name'=>$input['company'],'status'=>'active']);
    $role=App\Models\Role::create(['tenant_id'=>$tenant->id,'code'=>'ROLE-SPADMIN','name'=>'Super Admin','data_scope'=>'company']);$role->permissions()->sync(App\Models\Permission::pluck('id'));
    $user=App\Models\User::create(['tenant_id'=>$tenant->id,'name'=>'Chủ tịch','email'=>$input['email'],'password'=>Illuminate\Support\Facades\Hash::make($input['password']),'is_active'=>true]);$user->roles()->sync([$role->id]);
    App\Support\InitialAdministrator::create($tenant->id,$input['operator_email'] ?? '',$input['operator_password'] ?? '');
    App\Support\InstallationDefaultAccounts::provision(Illuminate\Support\Facades\DB::connection());
});
echo "SQLite UAT initialized. No demo data.\n";
