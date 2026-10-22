<?php
/** Pure decision: never treat an arbitrary partial schema as empty. */
function vkSqlEmptyDecision(array $objects,bool $resumeConfirmed,array $columns=[],?int $rows=null): bool {
    if($objects===[])return true;
    if(!$resumeConfirmed||count($objects)!==1)return false;
    $object=$objects[0];sort($columns);
    return ($object['schema_name']??null)==='dbo'&&($object['name']??null)==='migrations'
        &&($object['type']??null)==='U'&&$columns===['batch','id','migration']&&$rows===0;
}
function vkAssertSqlEmpty(PDO $pdo,bool $resumeConfirmed): void {
    if($pdo->query('SELECT SCHEMA_NAME()')->fetchColumn()!=='dbo')throw new RuntimeException('Default schema must be dbo');
    $objects=$pdo->query("SELECT SCHEMA_NAME(schema_id) AS schema_name,name,RTRIM(type) AS type FROM sys.objects WHERE is_ms_shipped=0 AND type IN ('U','V','P','FN','IF','TF','SN','SO','TR')")->fetchAll(PDO::FETCH_ASSOC);
    if($objects===[])return;
    $columns=[];$rows=null;
    if($resumeConfirmed&&count($objects)===1&&$objects[0]['schema_name']==='dbo'&&$objects[0]['name']==='migrations'&&$objects[0]['type']==='U'){
        $columns=$pdo->query("SELECT name FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.migrations')")->fetchAll(PDO::FETCH_COLUMN);
        $rows=(int)$pdo->query('SELECT COUNT_BIG(*) FROM dbo.migrations')->fetchColumn();
    }
    if(!vkSqlEmptyDecision($objects,$resumeConfirmed,$columns,$rows))throw new RuntimeException('Database is not empty; retain existing tables');
}
