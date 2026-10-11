<?php

namespace Tests\Feature;

use App\Models\KpiAssignment;
use App\Models\KpiDefinition;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Admin@123'])->assertOk()->json('data.access_token');
    }

    private function payload(User $user, string $type = 'user'): array
    {
        return ['kpi_definition_id' => KpiDefinition::where('tenant_id', $user->tenant_id)->where('code', 'KPI-WORKFLOW')->value('id'),
            'audience_type' => $type, 'audience_id' => $type === 'department' ? $user->department_id : $user->id,
            'period_type' => 'month', 'date' => '2026-09-12', 'metric' => 'tasks_completed', 'target_value' => 2, 'weight' => 1, 'direction' => 'increase', 'status' => 'active'];
    }

    public function test_employee_receives_department_assignment_and_personal_override_automatically(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $adminToken = $this->token($admin);
        $this->withToken($adminToken)->postJson('/api/v1/kpi/assignments', $this->payload($sales, 'department'))->assertCreated()->assertJsonPath('data.period_start', '2026-09-01T00:00:00.000000Z');
        $token = $this->token($sales);
        $this->withToken($token)->getJson('/api/v1/kpi/mine?date=2026-09-15')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.target', 2);
        $this->withToken($token)->postJson('/api/v1/kpi/assignments', $this->payload($sales))->assertForbidden();
        $this->withToken($adminToken)->postJson('/api/v1/kpi/assignments', array_replace($this->payload($sales), ['target_value' => 5]))->assertCreated();
        // Existing login token sees new assignment without logging out or loading the page again.
        $this->withToken($token)->getJson('/api/v1/kpi/mine?date=2026-09-15')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.target', 5);
        $this->withToken($token)->getJson('/api/v1/kpi/mine?date=2026-10-15')->assertOk()->assertJsonCount(0, 'data');
        $sales->update(['department_id' => null]);
        $this->withToken($token)->getJson('/api/v1/kpi/mine?date=2026-09-15')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_automatic_task_actuals_use_completion_evidence_not_creation_and_lock_is_immutable(): void
    {
        $this->seed();
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00'));
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        Task::query()->delete();
        $task = Task::create(['tenant_id' => $sales->tenant_id, 'code' => 'EVIDENCE', 'title' => 'Task', 'assignee_id' => $sales->id, 'created_by' => $admin->id, 'module' => 'sales', 'task_type' => 'manual', 'status' => 'completed', 'priority' => 'normal']);
        $history = TaskStatusHistory::create(['task_id' => $task->id, 'to_status' => 'completed', 'from_status' => 'new', 'changed_by' => $sales->id]);
        $history->forceFill(['created_at' => '2026-09-30 23:59:59'])->save();
        $adminToken = $this->token($admin);
        $id = $this->withToken($adminToken)->postJson('/api/v1/kpi/assignments', $this->payload($sales))->assertCreated()->json('data.id');
        $token = $this->token($sales);
        $this->withToken($token)->getJson('/api/v1/kpi/mine?date=2026-09-12')->assertOk()->assertJsonPath('data.0.actual', 1)->assertJsonPath('data.0.score', 50)->assertJsonPath('meta.summaries.0.score', 50);
        $this->withToken($token)->getJson('/api/v1/kpi/employee-report?date=2026-09-12')->assertOk()->assertJsonPath('data.0.kpi_score', 50)->assertJsonPath('data.0.kpi_indicators', 1);
        $this->withToken($token)->getJson('/api/v1/kpi/employee-report?date=2026-09-12')->assertOk()
            ->assertJsonPath('data.0.kpi_status', 'not_achieved')->assertJsonPath('data.0.kpi_locked', false)
            ->assertJsonPath('data.0.kpi_details.0.actual', 1)->assertJsonPath('data.0.kpi_details.0.target', 2);
        $this->withToken($adminToken)->postJson('/api/v1/kpi/assignments/'.$id.'/lock')->assertOk()->assertJsonPath('data.status', 'locked');
        $task->update(['status' => 'new']);
        $sales->update(['department_id' => null]);
        $this->withToken($token)->getJson('/api/v1/kpi/mine?date=2026-09-12')->assertOk()->assertJsonPath('data.0.actual', 1)->assertJsonPath('data.0.status', 'locked');
        $this->withToken($token)->getJson('/api/v1/kpi/employee-report?date=2026-09-12')->assertOk()
            ->assertJsonPath('data.0.kpi_locked', true)->assertJsonPath('data.0.kpi_details.0.actual', 1);
        $this->withToken($adminToken)->putJson('/api/v1/kpi/assignments/'.$id, $this->payload($sales))->assertStatus(422);
        $this->withToken($adminToken)->postJson('/api/v1/kpi/assignments', array_replace($this->payload($sales), ['audience_type' => 'company', 'audience_id' => 0]))->assertStatus(422);
        $locked = KpiAssignment::findOrFail($id)->locked_at;
        $this->withToken($adminToken)->postJson('/api/v1/kpi/assignments/'.$id.'/lock')->assertOk();
        $this->assertEquals($locked, KpiAssignment::findOrFail($id)->locked_at);
    }

    public function test_rejects_duplicate_unknown_metric_zero_target_and_foreign_tenant_audience(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $payload = $this->payload($admin);
        $token = $this->token($admin);
        $this->withToken($token)->postJson('/api/v1/kpi/assignments', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/v1/kpi/assignments', $payload)->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/kpi/assignments', array_replace($payload, ['metric' => 'arbitrary_sql']))->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/kpi/assignments', array_replace($payload, ['target_value' => 0]))->assertStatus(422);
        $tenant = Tenant::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $other = User::create(['tenant_id' => $tenant->id, 'name' => 'Other', 'email' => 'other@example.test', 'password' => 'Pass123456']);
        $this->withToken($token)->postJson('/api/v1/kpi/assignments', array_replace($payload, ['audience_id' => $other->id]))->assertStatus(422);
        $this->get('/my-kpi')->assertOk()->assertDontSee('Sales Demo');
    }
}
