<?php
if(PHP_SAPI!=='cli')exit(1);
$db=$argv[1]??'';
if(!preg_match('/^VKCRM_Trial_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/D',$db))throw new RuntimeException('Isolated test DB required');
$pdo=new PDO('sqlsrv:Server=localhost\MSSQLSERVER01;Database='.$db.';Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=5',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::SQLSRV_ATTR_QUERY_TIMEOUT=>5]);
$sql="SELECT t.name AS table_name,i.name AS index_name,c.name AS column_name,c.is_nullable FROM sys.indexes i JOIN sys.tables t ON t.object_id=i.object_id JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id JOIN sys.columns c ON c.object_id=t.object_id AND c.column_id=ic.column_id WHERE i.is_unique=1 AND i.has_filter=0 AND EXISTS (SELECT 1 FROM sys.index_columns x JOIN sys.columns y ON y.object_id=x.object_id AND y.column_id=x.column_id WHERE x.object_id=i.object_id AND x.index_id=i.index_id AND y.is_nullable=1) ORDER BY t.name,i.name,ic.key_ordinal";
echo json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC),JSON_PRETTY_PRINT).PHP_EOL;
