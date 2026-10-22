<?php

namespace App\Support;

class DeploymentPreflight
{
    /** Read-only checks: never write configuration, create databases or install dependencies. */
    public function inspect(string $target): array
    {
        $checks=[];
        $add=function(string $id,string $status,string $message) use (&$checks) {
            $checks[]=['id'=>$id,'status'=>$status,'message'=>$message];
        };
        $add('php',version_compare(PHP_VERSION,'8.2.0','>=')?'pass':'fail','PHP >= 8.2 required; detected '.PHP_VERSION);
        foreach (['bcmath','ctype','curl','dom','fileinfo','mbstring','openssl','PDO','tokenizer','xml','zip'] as $extension) {
            $add('extension.'.$extension,extension_loaded($extension)?'pass':'fail','PHP extension: '.$extension);
        }
        foreach ($target==='sqlsrv'?['sqlsrv','pdo_sqlsrv']:['pdo_sqlite'] as $extension) {
            $add('database_extension.'.$extension,extension_loaded($extension)?'pass':'fail','Target database extension: '.$extension);
        }
        $add('app_key',filled(config('app.key'))?'pass':'fail','Application encryption key must exist; value is not displayed.');
        $add('debug',config('app.debug')?'fail':'pass','Customer installation requires APP_DEBUG=false. Development settings are not changed.');
        $add('environment',app()->environment('production')?'pass':'warning','Customer installation should use APP_ENV=production.');
        foreach (['storage'=>storage_path(),'cache'=>base_path('bootstrap/cache')] as $id=>$path) {
            $add('writable.'.$id,is_dir($path)&&is_writable($path)?'pass':'fail','Writable application directory: '.$id);
        }
        $free=@disk_free_space(storage_path());
        $add('disk_space',$free===false?'warning':($free<5*1024**3?'fail':'pass'),$free===false?'Cannot determine free disk space.':'Free space: '.round($free/1024**3,2).' GiB; at least 5 GiB required by this preflight policy (actual needs depend on data and backups).');
        $add('file_storage',config('filesystems.default')==='local'?'pass':'warning','Current backup bundle supports local uploads only.');
        $add('database_target',$target===config('database.default')?'pass':'warning','Requested target: '.$target.'; configured default: '.config('database.default').'. No connection was changed.');
        if ($target==='sqlsrv') {
            $add('odbc','manual','Verify a supported Microsoft ODBC driver and PHP/ODBC version compatibility on the target machine.');
            $add('sqlserver_connection','manual','Run crm:sqlserver-check --connect against an isolated configured database.');
            $add('sqlserver_acceptance','manual','SQL Server migrations, business flows and native backup/restore are not yet certified.');
        }
        $add('production_server','manual','Configure a production web server, worker and scheduler services; do not deploy using artisan serve.');
        $add('tls','manual','Verify HTTPS certificate, network access and least-privilege service/database accounts.');
        $add('backup_restore','manual','Verify an off-machine encrypted backup and restore on an isolated machine before handover.');
        $blocked=count(array_filter($checks,fn($check)=>$check['status']==='fail'));
        return ['target'=>$target,'ready_for_handover'=>false,'automated_checks_passed'=>$blocked===0,'blocking_checks'=>$blocked,'checks'=>$checks];
    }
}
