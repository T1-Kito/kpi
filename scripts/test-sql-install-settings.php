<?php
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../deployment/installer/sql-install-settings.php';
$valid=['server'=>'localhost\\MSSQLSERVER01','database'=>'VKCRM_Test','auth'=>'sql','username'=>'installer','sql_password'=>'private'];
$cases=[['database'=>'master'],['database'=>'MSDB'],['database'=>'VKCRM_Test;DROP DATABASE master'],['database'=>'crm]bad'],['database'=>str_repeat('a',129)],['server'=>'localhost;Encrypt=no'],['auth'=>'unknown'],['username'=>''],['sql_password'=>"a\nb"]];
foreach($cases as $case){try{vkSqlSettings(array_replace($valid,$case));throw new LogicException('Invalid input accepted');}catch(RuntimeException $e){}}
foreach(['simple','space password','"quoted"',"apostrophe's",'back\\slash','${APP_KEY}', '$literal', '#hash', 'null', 'true','Tiếng Việt'] as $value){
    $parsed=Dotenv\Dotenv::parse('TEST='.vkEnvValue($value));
    if(($parsed['TEST']??null)!==$value)throw new RuntimeException('Environment round trip failed');
}
$windows=vkSqlSettings(array_replace($valid,['auth'=>'windows']));
if($windows['username']!==null||$windows['password']!==null)throw new RuntimeException('Windows auth leaked SQL credentials');
foreach(['crm','crm_crm','VKCRM_Test','CRM Sơn Tiên','crm-new'] as $database) {
    if(vkSqlSettings(array_replace($valid,['database'=>$database]))['database']!==$database)throw new RuntimeException('Database name was changed');
}
echo "PASS: 9 unsafe settings rejected; 5 database names preserved; 11 dotenv round trips; Windows authentication credential isolation.\n";
if(vkDatabaseMode([])!=='existing'||vkDatabaseMode(['database_mode'=>'create','confirm_empty_trial'=>true])!=='create')throw new RuntimeException('Database mode selection failed');
foreach([['database_mode'=>'drop'],['database_mode'=>'create','confirm_empty_trial'=>false]] as $case){try{vkDatabaseMode($case);throw new LogicException('Unsafe database mode accepted');}catch(RuntimeException $e){}}
echo "PASS: existing database default; explicit creation confirmation; invalid database actions rejected.\n";
