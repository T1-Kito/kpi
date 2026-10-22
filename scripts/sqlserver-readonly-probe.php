<?php
// Isolated local Windows-authentication probe. Does not load/change Laravel .env.
if (!extension_loaded('pdo_sqlsrv')) {
    fwrite(STDERR, "pdo_sqlsrv is required.\n"); exit(1);
}
try {
    // TrustServerCertificate is restricted to this local development probe.
    $pdo = new PDO('sqlsrv:Server=localhost;Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=5', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $statement=$pdo->prepare('SELECT CAST(? AS nvarchar(100)) AS unicode_test');
    $statement->execute(['Khách hàng Việt Nam']);
    if ($statement->fetchColumn() !== 'Khách hàng Việt Nam') throw new RuntimeException('Unicode check failed');
    echo "PHP SQL Server connection: OK\nVietnamese Unicode: OK\nRead-only probe completed; CRM configuration unchanged.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Probe failed; SQLSTATE: '.$exception->getCode().". No CRM configuration changed.\n");
    exit(1);
}
