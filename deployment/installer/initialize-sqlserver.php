<?php
// Experimental new-install only. CREATE requires explicit choice; never DROP/reset.
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/sql-install-settings.php';
require __DIR__.'/sql-install-diagnostics.php';
require __DIR__.'/sql-install-preflight.php';
$stage='INPUT';
$activeMigration=null;
try {
    $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    if(!filter_var($input['operator_email']??'',FILTER_VALIDATE_EMAIL)||strlen($input['operator_password']??'')<12||strcasecmp(trim($input['operator_email']??''),trim($input['email']??''))===0)throw new RuntimeException('Cần email riêng và mật khẩu ít nhất 12 ký tự cho Admin thường.');
    $settings=vkSqlSettings($input);
    $databaseMode=vkDatabaseMode($input);
    if(($input['confirm_empty_trial']??false)!==true)throw new RuntimeException('Trial confirmation required');
    if(!filter_var($input['email']??'',FILTER_VALIDATE_EMAIL)||strlen($input['password']??'')<12||trim($input['company']??'')==='')throw new RuntimeException('Invalid administrator details');
    if(is_file(__DIR__.'/.env')||is_file(__DIR__.'/sql-install-started.json'))throw new RuntimeException('Existing or interrupted installation; will not overwrite');
    foreach(['pdo_sqlsrv','bcmath','mbstring','openssl','fileinfo','zip'] as $extension)if(!extension_loaded($extension))throw new RuntimeException('Required runtime extension missing');
    $dsn='sqlsrv:Server='.$settings['server'].';Database='.$settings['database'].';Encrypt=yes;TrustServerCertificate='.($settings['trust']?'yes':'no').';LoginTimeout=5';
    if($databaseMode==='create'){
        $masterDsn='sqlsrv:Server='.$settings['server'].';Database=master;Encrypt=yes;TrustServerCertificate='.($settings['trust']?'yes':'no').';LoginTimeout=5';
        $master=new PDO($masterDsn,$settings['username'],$settings['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::SQLSRV_ATTR_QUERY_TIMEOUT=>15]);
        if((int)$master->query("SELECT HAS_PERMS_BY_NAME(NULL,NULL,'CREATE ANY DATABASE')")->fetchColumn()!==1)throw new RuntimeException('CREATE_DATABASE_PERMISSION');
        $exists=$master->prepare('SELECT DB_ID(?)');$exists->execute([$settings['database']]);
        if($exists->fetchColumn()!==null)throw new RuntimeException('DATABASE_EXISTS');
        // Validation excludes delimiters/DSN controls; still quote the identifier defensively.
        $master->exec('CREATE DATABASE ['.str_replace(']',']]',$settings['database']).']');
    }
    $stage='CONNECT';
    $pdo=new PDO($dsn,$settings['username'],$settings['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::SQLSRV_ATTR_QUERY_TIMEOUT=>15]);
    // A restricted login can see only some objects: never mistake hidden tables for an empty DB.
    $stage='OWNER';
    if((int)$pdo->query("SELECT CASE WHEN IS_ROLEMEMBER('db_owner')=1 OR IS_SRVROLEMEMBER('sysadmin')=1 THEN 1 ELSE 0 END")->fetchColumn()!==1)throw new RuntimeException('Trial installer requires database owner visibility');
    $stage='EMPTY';
    vkAssertSqlEmpty($pdo,($input['resume_empty_repository']??false)===true);
    $stage='LOCK';
    $lock=$pdo->query("SET NOCOUNT ON; DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=N'VKCRM_SETUP',@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0; SELECT @r")->fetchColumn();
    if((int)$lock<0)throw new RuntimeException('Another installer is using this database');
    // Recheck after taking the database-scoped application lock.
    vkAssertSqlEmpty($pdo,($input['resume_empty_repository']??false)===true);
    $stage='FILES';
    require __DIR__.'/vendor/autoload.php';
    foreach(['bootstrap/cache','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs'] as $directory){$path=__DIR__.'/'.$directory;if(!is_dir($path)&&!mkdir($path,0700,true))throw new RuntimeException('Cannot create application directory');}
    $marker=fopen(__DIR__.'/sql-install-started.json','x');if(!$marker)throw new RuntimeException('Cannot create installation marker');fwrite($marker,'{"state":"started"}');fclose($marker);
    $env="APP_NAME=VK-CRM-UAT\nAPP_ENV=local\nAPP_DEBUG=false\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nAPP_URL=http://127.0.0.1:8099\nDB_CONNECTION=sqlsrv\nDB_PORT=null\n";
    foreach(['DB_HOST'=>$settings['server'],'DB_DATABASE'=>$settings['database'],'DB_USERNAME'=>$settings['username'],'DB_PASSWORD'=>$settings['password']] as $name=>$value)$env.=$name.'='.vkEnvValue($value)."\n";
    $env.="SQLSERVER_RESTRICT_FOREIGN_KEYS=true\nDB_ENCRYPT=yes\nDB_TRUST_SERVER_CERTIFICATE=".($settings['trust']?'true':'false')."\nSESSION_DRIVER=file\nCACHE_STORE=file\nQUEUE_CONNECTION=sync\nMAIL_MAILER=log\nFILESYSTEM_DISK=local\n";
    $file=fopen(__DIR__.'/.env','x');if(!$file)throw new RuntimeException('Cannot create configuration');if(fwrite($file,$env)!==strlen($env))throw new RuntimeException('Configuration write failed');fclose($file);
    $stage='BOOTSTRAP';
    $app=require __DIR__.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    // Resolve exclusively from the validated input, not inherited machine environment variables.
    config(['database.default'=>'sqlsrv','database.connections.sqlsrv'=>['driver'=>'sqlsrv','options'=>[PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE=>true],'host'=>$settings['server'],'port'=>null,'database'=>$settings['database'],'username'=>$settings['username'],'password'=>$settings['password'],'charset'=>'utf8','prefix'=>'','prefix_indexes'=>true,'restrict_foreign_key_deletes'=>true,'encrypt'=>'yes','trust_server_certificate'=>$settings['trust']]]);
    Illuminate\Support\Facades\DB::purge('sqlsrv');
    $stage='SCHEMA';
    $app['events']->listen(Illuminate\Database\Events\MigrationStarted::class,function($event)use(&$activeMigration){
        $activeMigration=pathinfo((new ReflectionClass($event->migration))->getFileName(),PATHINFO_FILENAME);
    });
    if(Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'sqlsrv','--force'=>true])!==0)throw new RuntimeException('Migration failed');
    $stage='ADMIN';
    Illuminate\Support\Facades\DB::transaction(function()use($input){
        if(App\Models\User::exists()||App\Models\Tenant::exists())throw new RuntimeException('Database already initialized');
        $codes=['dashboard.chairman.view','master.cost.view'];
        foreach(Illuminate\Support\Facades\Route::getRoutes() as $route)foreach($route->gatherMiddleware() as $middleware)if(is_string($middleware)&&str_starts_with($middleware,'permission:'))$codes=array_merge($codes,explode('|',substr($middleware,11)));
        foreach(array_unique($codes) as $code)App\Models\Permission::firstOrCreate(['code'=>$code],['name'=>$code]);
        $tenant=App\Models\Tenant::create(['code'=>'CRM','name'=>$input['company'],'status'=>'active']);
        $role=App\Models\Role::create(['tenant_id'=>$tenant->id,'code'=>'ROLE-SPADMIN','name'=>'Super Admin','data_scope'=>'company']);$role->permissions()->sync(App\Models\Permission::pluck('id'));
        $user=App\Models\User::create(['tenant_id'=>$tenant->id,'name'=>'Chủ tịch','email'=>$input['email'],'password'=>Illuminate\Support\Facades\Hash::make($input['password']),'is_active'=>true]);$user->roles()->sync([$role->id]);
        App\Support\InitialAdministrator::create($tenant->id,$input['operator_email'] ?? '',$input['operator_password'] ?? '');
    });
    echo "SQL_INSTALL_OK\n";
}catch(Throwable $e){
    // Driver/migration exceptions may contain credentials or personal data; never print raw exceptions.
    if($e->getMessage()==='CREATE_DATABASE_PERMISSION')echo "SQL_CREATE_PERMISSION\n";
    elseif($e->getMessage()==='DATABASE_EXISTS')echo "SQL_DATABASE_EXISTS\n";
    else {
        echo 'SQL_STAGE_'.$stage."\n";
        $diagnostic=vkSqlDiagnostic($e,$stage,$activeMigration);
        echo 'SQL_DIAGNOSTIC:'.json_encode($diagnostic,JSON_THROW_ON_ERROR)."\n";
        // Only write safe diagnostics after this installer has reserved the directory.
        if(is_file(__DIR__.'/sql-install-started.json'))@file_put_contents(__DIR__.'/sql-install-diagnostic.json',json_encode($diagnostic,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }
    fwrite(STDERR,"SQL installation stopped. Check empty trial database, credentials, permissions, TLS and schema compatibility. Keep folder/database for diagnosis; no automatic deletion.\n");exit(1);
}
