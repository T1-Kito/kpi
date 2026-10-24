<?php
declare(strict_types=1);
// Synthetic SQLite fixture only. Never loads the workspace .env or a client connection.
$source = dirname(__DIR__);
$fixture = sys_get_temp_dir().'/VkCrmUpdateGate-'.bin2hex(random_bytes(8));
$stage = $fixture.'/stage';
mkdir($stage, 0700, true);
foreach (['app','bootstrap','config','routes'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = str_replace('\\','/',substr($file->getPathname(), strlen($source)+1));
        if (!$file->isFile() || $file->isLink() || str_starts_with($relative,'bootstrap/cache/')) continue;
        $destination = $stage.'/'.$relative;
        if (!is_dir(dirname($destination))) mkdir(dirname($destination),0700,true);
        copy($file->getPathname(),$destination);
    }
}
foreach (['vendor','storage/framework/cache/data','storage/framework/views','storage/framework/sessions','storage/logs'] as $dir) mkdir($fixture.'/'.$dir,0700,true);
mkdir($stage.'/bootstrap/cache',0700,true);
copy($source.'/composer.json',$stage.'/composer.json');
copy($source.'/artisan',$stage.'/artisan');
mkdir($stage.'/database/migrations',0700,true);
file_put_contents($fixture.'/vendor/autoload.php','<?php return require '.var_export($source.'/vendor/autoload.php',true).';');
$sql=in_array('--sql',$argv,true);
$dbPath = str_replace('\\','/',$fixture.'/fixture.sqlite');
$sqlName='VKCRM_BridgeTrial_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
if($sql) {
    $db=new PDO('sqlsrv:Server=lpc:localhost\\MSSQLSERVER01;Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=8',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE DATABASE ['.$sqlName.']');$db->exec('USE ['.$sqlName.']');
    echo 'Synthetic SQL fixture retained: '.$sqlName.PHP_EOL;
} else $db = new PDO('sqlite:'.$dbPath);
$db->exec($sql ? 'CREATE TABLE migrations (id INT IDENTITY PRIMARY KEY, migration VARCHAR(255) NOT NULL, batch INTEGER NOT NULL)' : 'CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL, batch INTEGER NOT NULL)');
$db->exec('CREATE TABLE customer_fixture (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$db->exec('CREATE TABLE service_tickets (id BIGINT PRIMARY KEY, subject VARCHAR(200) NOT NULL)');
$db->exec('CREATE TABLE customers (id BIGINT PRIMARY KEY, name VARCHAR(200) NOT NULL)');
$db->exec("INSERT INTO customers VALUES (1,'Existing customer retained')");
$db->exec("INSERT INTO customer_fixture VALUES (1,'Synthetic customer only')");
file_put_contents($stage.'/database/migrations/2026_01_01_000000_fixture.php','<?php // not executed');
$db->exec($sql ? "INSERT INTO migrations (migration,batch) VALUES ('2026_01_01_000000_fixture',1)" : "INSERT INTO migrations VALUES (1,'2026_01_01_000000_fixture',1)");
$db = null;
file_put_contents($fixture.'/.env',"APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nDB_CONNECTION=sqlite\nDB_DATABASE=\"$dbPath\"\nCACHE_STORE=array\nSESSION_DRIVER=array\nQUEUE_CONNECTION=sync\n");
if($sql)file_put_contents($fixture.'/.env',"APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nDB_CONNECTION=sqlsrv\nDB_HOST='lpc:localhost\\MSSQLSERVER01'\nDB_PORT=null\nDB_DATABASE=$sqlName\nDB_USERNAME=null\nDB_PASSWORD=null\nDB_ENCRYPT=yes\nDB_TRUST_SERVER_CERTIFICATE=true\nSQLSERVER_LOGIN_TIMEOUT=8\nCACHE_STORE=array\nSESSION_DRIVER=array\nQUEUE_CONNECTION=sync\n");
$before = $sql ? null : hash_file('sha256',$dbPath);
$envBefore = hash_file('sha256',$fixture.'/.env');
$env = getenv();
foreach (array_keys($env) as $key) if (preg_match('/^(APP|DB|SQLSERVER|CACHE|SESSION|QUEUE|JWT)_/i',$key)) unset($env[$key]);
$run = static function (bool $upgrade=false) use ($source,$fixture,$stage,$env): array {
    $command=[PHP_BINARY,$source.'/deployment/installer/update-readonly-check.php',$fixture,$stage];if($upgrade)$command[]='--upgrade-reviewed';
    $process = proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture,$env);
    if (!is_resource($process)) throw new RuntimeException('Cannot run gate');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]);fclose($pipes[1]); $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
    return [proc_close($process),$out,$err];
};
$assert = static function(bool $ok,string $name):void { if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL; };
$result=$run();
if ($result !== [0,'VK_UPDATE_COMPATIBLE','']) echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
$assert($result === [0,'VK_UPDATE_COMPATIBLE',''],'compatible fixture passes');
$assert(($sql || hash_file('sha256',$dbPath)===$before) && hash_file('sha256',$fixture.'/.env')===$envBefore,'database probe leaves configuration unchanged');
file_put_contents($stage.'/database/migrations/2026_01_02_000000_pending.php','<?php throw new RuntimeException("MUST NEVER EXECUTE");');
$result=$run();
$assert($result === [1,'','MIGRATION_REQUIRED'],'pending migration refused without running migration');
$assert($sql || hash_file('sha256',$dbPath)===$before,'pending migration rejected before DDL');
unlink($stage.'/database/migrations/2026_01_02_000000_pending.php');
foreach(['2026_10_20_100000_add_customer_feedback_details','2026_10_20_110000_link_feedback_documents','2026_10_20_120000_create_ticket_attachments','2026_10_22_100000_create_import_batch_tables','2026_10_22_110000_create_import_record_links','2026_10_22_120000_add_customer_business_classification'] as $migration)
    file_put_contents($stage.'/database/migrations/'.$migration.'.php','<?php throw new RuntimeException("MUST_NOT_EXECUTE_ARBITRARY_MIGRATION");');
$assert($run()===[0,'VK_UPDATE_ADDITIVE_REQUIRED',''],'reviewed additions detected without database writes');
$assert($run(true)===[1,'','UPGRADE_MAINTENANCE'],'upgrade requires maintenance marker');
file_put_contents($fixture.'/storage/framework/down','{"source":"VK_CRM_SOFTWARE_UPDATE"}');
$upgradeResult=$run(true);if($upgradeResult!==[0,'VK_UPDATE_COMPATIBLE',''])echo json_encode($upgradeResult).PHP_EOL;
$assert($upgradeResult===[0,'VK_UPDATE_COMPATIBLE',''],'reviewed additive upgrade succeeds without executing migration file');
$assert(count(glob($fixture.'/storage/app/private/database-backups/'.($sql?'upgrade-*.json':'*.sqlite')))===1,'verified backup or server-side backup receipt retained');
$assert($run()===[0,'VK_UPDATE_COMPATIBLE',''],'upgraded schema accepted on subsequent check');
$assert(hash_file('sha256',$fixture.'/.env')===$envBefore,'configuration remains byte-identical after upgrade');
if($sql) {
    $verify=new PDO('sqlsrv:Server=lpc:localhost\\MSSQLSERVER01;Database='.$sqlName.';Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=8',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $assert($verify->query('SELECT name FROM customer_fixture')->fetchColumn()==='Synthetic customer only','SQL customer data preserved after bridge upgrade');
    $assert((int)$verify->query("SELECT COUNT(*) FROM sys.tables WHERE name='ticket_attachments'")->fetchColumn()===1,'SQL attachment table exists after bridge upgrade');
    $verify=null;
}
file_put_contents($stage.'/app/Providers/AppServiceProvider.php','<?php namespace App\\Providers; class AppServiceProvider extends \\Illuminate\\Support\\ServiceProvider { public function register(): void { throw new \\RuntimeException("PRIVATE_DRIVER_DETAIL_MUST_NOT_LEAK"); }}');
$result=$run();
$assert($result === [1,'','CONNECTION_OR_BOOTSTRAP'],'optimized Composer map loads staged code; exception details are hidden');
echo 'TOTAL '.($sql?13:11).' PASS. Driver: '.($sql?'SQL Server':'SQLite').'; synthetic fixture only.'.PHP_EOL;
