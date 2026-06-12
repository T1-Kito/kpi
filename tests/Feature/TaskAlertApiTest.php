<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAlertApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_task_with_sla_due_date(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');

        $response = $this->withToken($token)
            ->postJson('/api/v1/tasks', [
                'title' => 'Task SLA test',
                'module' => 'general',
                'task_type' => 'manual',
                'priority' => 'normal',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Task SLA test')
            ->assertJsonPath('data.status', 'new');

        $this->assertNotNull($response->json('data.due_at'));
    }

    public function test_overdue_command_marks_task_and_creates_alert(): void
    {
        $this->seed();

        $task = Task::where('code', 'TASK-DEMO-ADM')->firstOrFail();
        $task->update(['due_at' => now()->subMinute(), 'status' => 'new']);

        $this->artisan('tasks:check-overdue')
            ->expectsOutput('Marked 1 task(s) as overdue.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'overdue']);
        $this->assertDatabaseHas('alerts', [
            'alert_type' => 'task_overdue',
            'source_type' => 'Task',
            'source_id' => $task->id,
            'status' => 'open',
        ]);
    }

    public function test_sales_can_list_own_tasks_and_notifications(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');

        $this->withToken($token)
            ->getJson('/api/v1/tasks?mine=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withToken($token)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_task_creator_can_load_form_lookups(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');

        $this->withToken($token)
            ->getJson('/api/v1/lookups/users')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'admin@vk-kpi.local');

        $this->withToken($token)
            ->getJson('/api/v1/lookups/departments')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'code', 'name']]]);
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
