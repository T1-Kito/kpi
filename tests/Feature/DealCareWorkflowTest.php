<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Task;
use App\Models\User;
use App\Models\VkNotification;
use App\Support\DealService;
use App\Support\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealCareWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $actor->email, 'password' => 'Admin@123'])->json('data.access_token');
        $deal = app(DealService::class)->create($actor, ['name' => 'Care workflow']);
        $task = app(TaskService::class)->create($actor, ['title' => 'Gọi xác nhận nhu cầu', 'module' => 'sales', 'task_type' => 'deal_follow_up', 'source_type' => 'Deal', 'source_id' => $deal->id, 'assignee_id' => $actor->id]);

        return [$actor, $token, $deal, $task];
    }

    public function test_result_is_logged_with_actor_and_follow_up_is_created_once(): void
    {
        [$actor, $token, $deal, $task] = $this->scenario();
        $url = "/api/v1/deals/{$deal->id}/activities/{$task->id}/complete";
        $payload = ['outcome' => 'contacted', 'note' => 'Khách cần demo', 'create_next' => true];
        $response = $this->withToken($token)->postJson($url, $payload)->assertOk()->assertJsonPath('data.repeated', false);
        $nextId = $response->json('data.next_task.id');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'completed']);
        $this->assertDatabaseHas('task_status_histories', ['task_id' => $task->id, 'to_status' => 'completed', 'changed_by' => $actor->id, 'reason' => "Đã trao đổi\nKhách cần demo"]);
        $this->assertTrue(Task::findOrFail($nextId)->due_at->equalTo($deal->refresh()->next_activity_at));
        $this->withToken($token)->postJson($url, $payload)->assertOk()->assertJsonPath('data.repeated', true);
        $this->assertSame(2, Task::where('source_type', 'Deal')->where('source_id', $deal->id)->count());
        $detail = $this->withToken($token)->getJson("/api/v1/deals/{$deal->id}")->assertOk();
        $this->assertContains("Đã trao đổi\nKhách cần demo", array_column($detail->json('data.timeline'), 'details'));
        $this->assertContains($actor->name, array_column($detail->json('data.timeline'), 'actor'));
    }

    public function test_no_next_task_clears_next_date_and_invalid_date_does_not_complete(): void
    {
        [, $token, $deal, $task] = $this->scenario();
        $url = "/api/v1/deals/{$deal->id}/activities/{$task->id}/complete";
        $this->withToken($token)->postJson($url, ['outcome' => 'waiting', 'create_next' => true, 'next_due_at' => now()->subDay()->toDateTimeString()])->assertStatus(422);
        $this->assertSame('new', $task->refresh()->status);
        $this->withToken($token)->postJson($url, ['outcome' => 'waiting', 'create_next' => false])->assertOk();
        $this->assertNull($deal->refresh()->next_activity_at);
        $this->assertSame(1, Task::where('source_type', 'Deal')->where('source_id', $deal->id)->count());
    }

    public function test_care_mutations_are_owner_scoped_and_tasks_cannot_be_swapped(): void
    {
        [$actor, $token, $deal, $task] = $this->scenario();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $other = app(DealService::class)->create($admin, ['name' => 'Private care']);
        $this->withToken($token)->postJson("/api/v1/deals/{$other->id}/activities", ['title' => 'Forbidden'])->assertNotFound();
        $this->withToken($token)->postJson("/api/v1/deals/{$other->id}/activities/{$task->id}/complete", ['outcome' => 'contacted', 'create_next' => true])->assertNotFound();
        $another = app(DealService::class)->create($actor, ['name' => 'Another']);
        $this->withToken($token)->postJson("/api/v1/deals/{$another->id}/activities/{$task->id}/complete", ['outcome' => 'contacted', 'create_next' => false])->assertNotFound();
        $deal->update(['status' => 'lost']);
        $this->withToken($token)->postJson("/api/v1/deals/{$deal->id}/activities/{$task->id}/complete", ['outcome' => 'contacted', 'create_next' => true])->assertStatus(409);
        $this->assertSame('new', $task->refresh()->status);
    }

    public function test_reminders_are_repeat_safe_and_resolved_when_result_is_saved(): void
    {
        [$actor, $token, $deal, $task] = $this->scenario();
        $task->update(['due_at' => now()->subMinute()]);
        $this->artisan('tasks:check-overdue')->assertExitCode(0);
        $this->artisan('tasks:check-overdue')->assertExitCode(0);
        $this->assertSame('overdue', $task->refresh()->status);
        $this->assertSame(1, $task->history()->where('to_status', 'overdue')->count());
        $this->assertSame(1, Alert::where('source_type', 'Task')->where('source_id', $task->id)->where('alert_type', 'task_overdue')->count());
        $this->assertSame(1, VkNotification::where('source_type', 'Task')->where('source_id', $task->id)->where('title', 'Task quá hạn')->count());
        $this->assertSame(1, VkNotification::where('source_type', 'Task')->where('source_id', $task->id)->where('title', 'Công việc mới')->count());
        $this->withToken($token)->postJson("/api/v1/deals/{$deal->id}/activities/{$task->id}/complete", ['outcome' => 'contacted', 'create_next' => false])->assertOk();
        $this->assertDatabaseHas('alerts', ['source_type' => 'Task', 'source_id' => $task->id, 'status' => 'resolved']);
        $this->assertDatabaseHas((new VkNotification)->getTable(), ['source_type' => 'Task', 'source_id' => $task->id, 'title' => 'Task quá hạn', 'status' => 'read']);
    }
}
