<?php

namespace Tests\Feature;

use App\Models\KpiAdjustment;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\Kpi\KpiReportWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class EmployeeKpiReportTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $actor): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $actor->email, 'password' => 'Admin@123'])->assertOk()->json('data.access_token');
    }

    public function test_report_and_export_enforce_scope_and_period_without_granting_configuration_rights(): void
    {
        $this->seed();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        Task::query()->delete();
        KpiAdjustment::query()->delete();
        foreach ([$sales, $admin] as $employee) {
            foreach (['2026-10-01 00:00:00', '2026-10-31 23:59:59', '2026-11-01 00:00:00'] as $index => $date) {
                $task = Task::create(['tenant_id' => $employee->tenant_id, 'assignee_id' => $employee->id, 'code' => 'T-'.$employee->id.'-'.$index, 'title' => 'Report task', 'module' => 'sales', 'task_type' => 'manual', 'priority' => 'normal', 'status' => 'completed', 'created_by' => $admin->id]);
                $task->forceFill(['created_at' => $date])->save();
            }
            foreach ([['bonus', 7, 'approved'], ['penalty', 2, 'approved'], ['bonus', 50, 'pending']] as [$type, $points, $status]) {
                KpiAdjustment::create(['tenant_id' => $employee->tenant_id, 'user_id' => $employee->id, 'created_by' => $admin->id,
                    'adjustment_type' => $type, 'points' => $points, 'status' => $status, 'period_type' => 'month',
                    'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'reason' => 'Report evidence']);
            }
        }
        $query = '/api/v1/kpi/employee-report?date=2026-10-15';
        $token = $this->token($sales);
        $this->withToken($token)->getJson($query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $sales->id)->assertJsonPath('data.0.tasks_created', 2)
            ->assertJsonPath('data.0.tasks_completed', 2)->assertJsonPath('data.0.net_points', 5)
            ->assertJsonPath('data.0.pending_adjustments', 1)->assertJsonCount(1, 'employees');
        $this->withToken($token)->getJson($query)->assertOk()->assertJsonPath('data.0.kpi_status', 'unassigned')
            ->assertJsonPath('data.0.kpi_score', null)->assertJsonCount(0, 'data.0.kpi_details')
            ->assertJsonPath('data.0.tasks_not_started', 0)->assertJsonPath('data.0.tasks_in_progress', 0);
        $this->withToken($token)->getJson($query.'&user_id='.$admin->id)->assertOk()->assertJsonCount(0, 'data');
        $response = $this->withToken($token)->get('/api/v1/kpi/employee-report/export?date=2026-10-15')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $summary = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString($sales->name, $summary);
        $this->assertStringNotContainsString('<t xml:space="preserve">'.$admin->name.'</t>', $summary);
        $this->assertStringContainsString('<c r="I7" s="2"><v>5</v></c>', $summary);
        $zip->close();
        @unlink($path);
        $this->withToken($token)->postJson('/api/v1/kpi/definitions', [])->assertForbidden();
        $sales->roles()->sync([Role::where('code', 'ROLE-MGR')->value('id')]);
        $this->withToken($token)->getJson($query)->assertOk()->assertJsonPath('meta.scope', 'department');
        $sales->update(['department_id' => null]);
        $this->withToken($token)->getJson($query)->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($this->token($admin))->getJson($query.'&user_id='.$sales->id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_real_xlsx_contains_typed_numbers_and_safe_text_not_formulas(): void
    {
        $report = ['meta' => ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'as_of' => '2026-10-11', 'task_basis' => 'Công việc tạo trong kỳ', 'points_basis' => 'Điểm đã duyệt'],
            'rows' => [['user_id' => 1, 'name' => '=HYPERLINK("https://example.test")', 'department' => 'Sales & Ops', 'position' => null, 'tasks_created' => 0, 'tasks_completed' => 0, 'tasks_overdue' => 0, 'bonus_points' => 2.5, 'penalty_points' => 5, 'net_points' => -2.5, 'pending_adjustments' => 0]], 'tasks' => [], 'adjustments' => []];
        $path = app(KpiReportWorkbook::class)->write($report);
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
            $this->assertStringContainsString('Tổng hợp', $zip->getFromName('xl/workbook.xml'));
            foreach (range(1, 4) as $id) {
                $xml = $zip->getFromName('xl/worksheets/sheet'.$id.'.xml');
                $this->assertNotFalse(simplexml_load_string($xml));
                $this->assertStringNotContainsString('<f>', $xml);
            }
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringContainsString('=HYPERLINK', $sheet);
            $this->assertStringContainsString('Sales &amp; Ops', $sheet);
            $this->assertStringContainsString('<v>-2.5</v>', $sheet);
            $this->assertStringContainsString('state="frozen"', $sheet);
            $zip->close();
        } finally {
            @unlink($path);
        }
    }

    public function test_report_validation_public_shell_and_unauthorized_export(): void
    {
        $this->get('/kpi-reports')->assertOk()->assertSee('kpiReportRoot')->assertDontSee('Sales Demo');
        $this->getJson('/api/v1/kpi/employee-report/export')->assertUnauthorized();
        $this->seed();
        $user = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $this->withToken($this->token($user))->getJson('/api/v1/kpi/employee-report?date=bad&period_type=hour')->assertStatus(422);
    }
}
