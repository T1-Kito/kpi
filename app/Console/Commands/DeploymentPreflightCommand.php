<?php
namespace App\Console\Commands;

use App\Support\DeploymentPreflight;
use Illuminate\Console\Command;

class DeploymentPreflightCommand extends Command
{
    protected $signature='crm:preflight {--target=sqlite : sqlite or sqlsrv} {--json : Machine-readable report without secrets}';
    protected $description='Read-only installation readiness report; does not certify production readiness';

    public function handle(DeploymentPreflight $preflight): int
    {
        $target=$this->option('target');
        if (!in_array($target,['sqlite','sqlsrv'],true)) {
            if ($this->option('json')) $this->line(json_encode(['error'=>'Unsupported target'],JSON_THROW_ON_ERROR));
            else $this->error('Supported targets: sqlite, sqlsrv.');
            return self::INVALID;
        }
        $report=$preflight->inspect($target);
        if ($this->option('json')) $this->line(json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        else {
            $this->table(['Check','Status','Action / information'],array_map(fn($row)=>[$row['id'],$row['status'],$row['message']],$report['checks']));
            $this->warn('This report is not production certification. Manual checks and acceptance testing remain required.');
        }
        return $report['automated_checks_passed']?self::SUCCESS:self::FAILURE;
    }
}
