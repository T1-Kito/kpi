<?php
// Local instance only; session-scoped temp table, transaction always rolled back.
if(PHP_SAPI!=='cli')exit(1);
$pdo=new PDO('sqlsrv:Server=localhost\MSSQLSERVER01;Database=tempdb;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=5',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::SQLSRV_ATTR_QUERY_TIMEOUT=>5]);
$pdo->beginTransaction();
try {
    $pdo->exec('CREATE TABLE #quotation_revision_probe (id int NOT NULL, revision_root_id int NULL, revision_number int NOT NULL)');
    $pdo->exec('CREATE UNIQUE INDEX probe_revision ON #quotation_revision_probe(revision_root_id,revision_number)');
    $pdo->exec('INSERT INTO #quotation_revision_probe VALUES (1,NULL,1)');
    try {
        $pdo->exec('INSERT INTO #quotation_revision_probe VALUES (2,NULL,1)');
        throw new LogicException('Expected original SQL Server constraint to reject duplicate NULL roots');
    } catch(PDOException $e) {
        if(!in_array((int)($e->errorInfo[1]??0),[2601,2627],true))throw $e;
        echo "Original NULL-root failure reproduced.\n";
    }
    $pdo->exec('DROP INDEX probe_revision ON #quotation_revision_probe');
    $pdo->exec('CREATE UNIQUE INDEX probe_revision ON #quotation_revision_probe(revision_root_id,revision_number) WHERE revision_root_id IS NOT NULL');
    $pdo->exec('INSERT INTO #quotation_revision_probe VALUES (2,NULL,1),(3,1,2),(4,2,2)');
    try {
        $pdo->exec('INSERT INTO #quotation_revision_probe VALUES (5,1,2)');
        throw new LogicException('Revision family uniqueness was lost');
    } catch(PDOException $e) {
        if(!in_array((int)($e->errorInfo[1]??0),[2601,2627],true))throw $e;
    }
    if((int)$pdo->query('SELECT COUNT(*) FROM #quotation_revision_probe')->fetchColumn()!==4)throw new LogicException('Unexpected rows');
    echo "Filtered index: independent roots allowed; duplicate family revision blocked. PASS\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    echo "ROLLBACK complete; no customer tables accessed.\n";
}
