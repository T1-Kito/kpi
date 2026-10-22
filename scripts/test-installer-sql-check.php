<?php
// No database writes. Default checks need no SQL Server installation.
$helper=dirname(__DIR__).'/deployment/installer/sqlserver-connect.php';
function probe(array $input,array $extra=[]):array {
    global $helper;
    $process=proc_open([PHP_BINARY,'-n',...$extra,$helper],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR));fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($process);
    if($error!=='')throw new RuntimeException('Unexpected runtime warning');
    $result=json_decode($output,true,512,JSON_THROW_ON_ERROR);
    if($exit!==($result['ok']?0:1))throw new RuntimeException('Inconsistent result');
    if(str_contains($output,'SECRET_TEST_PASSWORD'))throw new RuntimeException('Password leaked');
    return $result;
}
$valid=['server'=>'localhost','database'=>'master','auth'=>'sql','username'=>'test','password'=>'SECRET_TEST_PASSWORD'];
$cases=[
    ['server'=>''], ['server'=>'localhost;Encrypt=no'],['server'=>"localhost\n".'bad'],
    ['database'=>'master;DROP DATABASE test'],['auth'=>'invalid'],['username'=>''],
];
foreach($cases as $case){$result=probe(array_replace($valid,$case));if($result['code']!=='INPUT')throw new RuntimeException('Input validation failed');}
if(probe($valid)['code']!=='DRIVER')throw new RuntimeException('Missing driver not detected');
echo "PASS: 6 input-validation cases; missing-driver detection; JSON/exit consistency; no password disclosure.\n";
if(in_array('--local-readonly',$argv,true)){
    $driver=dirname(__DIR__).'/.tmp/sqlserver-runtime/drivers/Windows/php_pdo_sqlsrv_82_ts_x64.dll';
    $server='localhost';
    foreach($argv as $argument)if(str_starts_with($argument,'--server='))$server=substr($argument,9);
    $result=probe(['server'=>$server,'database'=>'master','auth'=>'windows','trust_certificate'=>true],['-d','extension='.$driver]);
    echo 'Local read-only connection: '.$result['code'].' — '.$result['message'].PHP_EOL;
    exit($result['ok']?0:2);
}
