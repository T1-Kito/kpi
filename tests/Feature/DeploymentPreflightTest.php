<?php
namespace Tests\Feature;

use App\Support\DeploymentPreflight;
use Tests\TestCase;

class DeploymentPreflightTest extends TestCase
{
    public function test_report_is_read_only_and_does_not_expose_secrets_or_claim_handover_ready(): void
    {
        $before=config('database.default');
        config(['app.key'=>'secret-preflight-key','app.debug'=>true,'database.connections.sqlsrv.password'=>'secret-database-password']);
        $report=app(DeploymentPreflight::class)->inspect('sqlsrv');
        $serialized=json_encode($report);
        $this->assertStringNotContainsString('secret-preflight-key',$serialized);
        $this->assertStringNotContainsString('secret-database-password',$serialized);
        $this->assertFalse($report['ready_for_handover']);
        $this->assertFalse($report['automated_checks_passed']);
        $this->assertSame('fail',collect($report['checks'])->firstWhere('id','debug')['status']);
        $this->assertSame($before,config('database.default'));
    }

    public function test_command_rejects_unknown_database_target(): void
    {
        $this->artisan('crm:preflight --target=unknown --json')->expectsOutput('{"error":"Unsupported target"}')->assertExitCode(2);
    }
}
