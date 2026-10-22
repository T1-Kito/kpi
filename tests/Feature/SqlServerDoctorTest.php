<?php
namespace Tests\Feature;

use Tests\TestCase;

class SqlServerDoctorTest extends TestCase
{
    public function test_driver_check_does_not_change_default_database(): void
    {
        $before=config('database.default');
        $available=extension_loaded('sqlsrv') && extension_loaded('pdo_sqlsrv');
        $this->artisan('crm:sqlserver-check')->assertExitCode($available?0:1);
        $this->assertSame($before,config('database.default'));
    }
}
