<?php
require __DIR__.'/../deployment/installer/sql-install-diagnostics.php';
$secret='DO_NOT_LEAK_credential';
$exception=new PDOException("SQL Server multiple cascade paths; password=$secret; private SQL values");
$exception->errorInfo=['42000',1785,'private driver text '.$secret];
$diagnostic=vkSqlDiagnostic($exception,'SCHEMA','0001_01_01_000000_create_users_table');
if($diagnostic['reason']!=='FOREIGN_KEY_CASCADE'||$diagnostic['native']!==1785||$diagnostic['sqlstate']!=='42000')throw new RuntimeException('Classification failed');
if(str_contains(json_encode($diagnostic),$secret)||str_contains(json_encode($diagnostic),'private'))throw new RuntimeException('Secret disclosed');
$unsafe=vkSqlDiagnostic(new RuntimeException($secret),'bad '.$secret,'path/'.$secret);
if($unsafe['stage']!=='UNKNOWN'||$unsafe['migration']!==null)throw new RuntimeException('Untrusted identifiers disclosed');
echo "PASS: safe SQLSTATE/native/migration classification; raw errors and credentials suppressed.\n";
