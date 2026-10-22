<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SqlServerDoctor extends Command
{
    protected $signature='crm:sqlserver-check {--connect : Test the configured sqlsrv connection with read-only queries}';
    protected $description='Check SQL Server prerequisites without changing database or running migrations';

    public function handle(): int
    {
        $ready=true;
        foreach (['sqlsrv','pdo_sqlsrv'] as $extension) {
            $present=extension_loaded($extension);
            $this->line($extension.': '.($present?'OK':'MISSING'));
            $ready=$ready && $present;
        }
        $this->line('PDO sqlsrv: '.(in_array('sqlsrv',\PDO::getAvailableDrivers(),true)?'OK':'MISSING'));
        $this->line('Default database unchanged: '.config('database.default'));
        $this->warn('ODBC/version compatibility, TLS certificate and SQL Server backup permissions still require verification on the target server.');
        if (!$ready) {
            $this->error('Missing PHP SQL Server drivers. No connection or migration attempted.');
            return self::FAILURE;
        }
        if (!$this->option('connect')) {
            $this->info('PHP driver checks passed. Connection and application compatibility have NOT been tested.');
            return self::SUCCESS;
        }
        try {
            $connection=DB::connection('sqlsrv');
            $connection->select('SELECT 1 AS connection_test');
            $result=$connection->selectOne('SELECT CAST(? AS nvarchar(100)) AS unicode_test',['Khách hàng Việt Nam']);
            if (($result->unicode_test??null)!=='Khách hàng Việt Nam') throw new \RuntimeException('Unicode round-trip failed');
            $this->info('Connection and Vietnamese Unicode: OK. No data was written.');
            $this->warn('This does not certify migrations, reports, concurrency or restore compatibility.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Driver exceptions can include server names or connection details: do not print raw messages.
            $this->error('Connection/Unicode check failed. Check credentials, network, TLS and driver compatibility privately.');
            return self::FAILURE;
        } finally {DB::disconnect('sqlsrv');}
    }
}
