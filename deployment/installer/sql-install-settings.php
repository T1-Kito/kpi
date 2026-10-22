<?php
// Pure validation/serialization shared by the CLI installer and its tests.
function vkSqlSettings(array $input): array {
    $server=trim($input['server']??'');$database=trim($input['database']??'');
    if($server===''||strlen($server)>255||preg_match('/[;{}\x00-\x1f]/',$server))throw new RuntimeException('Invalid server');
    // Preserve the selected name exactly. Explicit consent and empty-object checks still apply.
    if(!preg_match('/\A[\p{L}\p{N}_ -]{1,128}\z/u',$database)
        || in_array(strtolower($database),['master','model','msdb','tempdb'],true)) {
        throw new RuntimeException('Invalid or system database name');
    }
    $auth=$input['auth']??'sql';
    if(!in_array($auth,['sql','windows'],true))throw new RuntimeException('Invalid authentication');
    $username=$auth==='windows'?null:($input['username']??'');
    $password=$auth==='windows'?null:($input['sql_password']??'');
    if($auth==='sql'&&trim($username)==='')throw new RuntimeException('SQL username required');
    foreach([$username,$password] as $value)if($value!==null&&preg_match('/[\x00-\x1f]/',$value))throw new RuntimeException('Invalid credential characters');
    return ['server'=>$server,'database'=>$database,'username'=>$username,'password'=>$password,'trust'=>($input['trust_certificate']??false)===true];
}
function vkEnvValue(?string $value): string {
    if($value===null)return 'null';
    if(preg_match('/[\x00-\x1f]/',$value))throw new RuntimeException('Invalid environment value');
    return '"'.str_replace(['\\','"','$'],['\\\\','\\"','\\$'],$value).'"';
}
function vkDatabaseMode(array $input): string {
    $mode=$input['database_mode']??'existing';
    if(!in_array($mode,['existing','create'],true))throw new RuntimeException('Invalid database action');
    if($mode==='create'&&($input['confirm_empty_trial']??false)!==true)throw new RuntimeException('Creation requires explicit confirmation');
    return $mode;
}
