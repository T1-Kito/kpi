<?php
if(PHP_SAPI!=='cli')exit(1);
$pdo=new PDO('sqlsrv:Server=localhost\MSSQLSERVER01;Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=5',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::SQLSRV_ATTR_QUERY_TIMEOUT=>5]);
$q=$pdo->prepare("SELECT r.session_id,r.status,r.command,r.wait_type,r.wait_time,r.blocking_session_id,r.total_elapsed_time FROM sys.dm_exec_requests r JOIN sys.dm_exec_sessions s ON s.session_id=r.session_id WHERE r.session_id<>@@SPID AND s.is_user_process=1 ORDER BY r.total_elapsed_time DESC");
$q->execute();
echo json_encode($q->fetchAll(PDO::FETCH_ASSOC),JSON_PRETTY_PRINT).PHP_EOL;
