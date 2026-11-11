<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAlertApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_all_notifications_read_is_scoped_to_current_recipient(): void
    {
        $this->seed();
        $user = \App\Models\User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $other = \App\Models\User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        foreach (range(1, 12) as $number) {
            \App\Models\VkNotification::create(['tenant_id' => $user->tenant_id, 'recipient_id' => $user->id, 'title' => 'Thông báo '.$number, 'message' => 'Kiểm tra', 'status' => 'unread']);
        }
        $protected = \App\Models\VkNotification::create(['tenant_id' => $other->tenant_id, 'recipient_id' => $other->id, 'title' => 'Riêng tư', 'message' => 'Kiểm tra', 'status' => 'unread']);
        $token = $this->loginAs('sales@vk-kpi.local');
        $this->withToken($token)->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertSame(0, \App\Models\VkNotification::where('recipient_id', $user->id)->where('status', 'unread')->count());
        $this->assertSame('unread', $protected->fresh()->status);
        $this->withToken($token)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 0);
    }

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

    public function test_task_code_is_generated_by_system_even_when_payload_contains_code(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');

        $response = $this->withToken($token)
            ->postJson('/api/v1/tasks', [
                'code' => 'TASK-HACK-001',
                'title' => 'Task auto code test',
                'module' => 'general',
                'task_type' => 'manual',
                'priority' => 'normal',
            ])
            ->assertCreated();

        $code = $response->json('data.code');
        $this->assertNotSame('TASK-HACK-001', $code);
        $this->assertMatchesRegularExpression('/^TASK-\d{5}$/', $code);
        $this->assertDatabaseMissing('tasks', ['code' => 'TASK-HACK-001']);
    }
    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
