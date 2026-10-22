<?php
// Exercises real CLI entrypoint on invalid inputs; never creates or connects to a database.
$root=dirname(__DIR__).'/deployment/installer';
$base=['server'=>'localhost','database'=>'VKCRM_Test','auth'=>'windows','email'=>'trial@example.test','password'=>str_repeat('a',16),'company'=>'Trial','confirm_empty_trial'=>true];
$cases=[['database'=>'master'],['database'=>'crm;Encrypt=no'],['server'=>'host;Encrypt=no'],['confirm_empty_trial'=>false],['email'=>'invalid'],['password'=>'short']];
foreach($cases as $case){
    $process=proc_open([PHP_BINARY,'-n',$root.'/initialize-sqlserver.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode(array_replace($base,$case),JSON_THROW_ON_ERROR));fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==1||!str_starts_with($output,"SQL_STAGE_INPUT\nSQL_DIAGNOSTIC:")||!str_contains($error,'SQL installation stopped.'))throw new RuntimeException('Guard failed');
    if(file_exists($root.'/.env')||file_exists($root.'/sql-install-started.json'))throw new RuntimeException('Guard wrote install state');
}
echo "PASS: 6 SQL CLI pre-write guards; no database connection, no configuration writes.\n";
