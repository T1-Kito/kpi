<?php

namespace App\Support;

use App\Models\Task;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Models\VkNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly CodeGenerator $codes,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Task
    {
        return DB::transaction(function () use ($actor, $data) {
            $dueAt = $data['due_at'] ?? $this->calculateDueAt(
                $actor->tenant_id,
                $data['module'] ?? 'general',
                $data['task_type'],
                $data['priority'] ?? 'normal',
            );

            $task = Task::create([
                'tenant_id' => $actor->tenant_id,
                'code' => $data['code'] ?? $this->codes->next('tasks', 'code', 'TASK-', fn ($query) => $query->where('tenant_id', $actor->tenant_id)),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'task_type' => $data['task_type'],
                'priority' => $data['priority'] ?? 'normal',
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'assignee_id' => $data['assignee_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'due_at' => $dueAt,
                'status' => 'new',
                'created_by' => $actor->id,
            ]);

            $task->history()->create([
                'from_status' => null,
                'to_status' => 'new',
                'changed_by' => $actor->id,
                'reason' => 'created',
            ]);

            $this->audit->record('task', $task->id, 'create_task', $actor, null, $task->toArray());
            $this->events->publish($actor->tenant_id, 'TaskCreated', 'Task', $task->id, ['code' => $task->code]);

            if ($task->assignee_id) {
                VkNotification::firstOrCreate(
                    [
                        'tenant_id' => $task->tenant_id,
                        'recipient_id' => $task->assignee_id,
                        'source_type' => 'Task',
                        'source_id' => $task->id,
                    ],
                    [
                        'title' => 'Công việc mới',
                        'message' => $task->code.' - '.$task->title,
                        'action_url' => '/tasks',
                        'status' => 'unread',
                    ],
                );
            }

            return $task;
        });
    }

    public function changeStatus(Task $task, User $actor, string $status, ?string $reason = null): Task
    {
        return DB::transaction(function () use ($task, $actor, $status, $reason) {
            $old = $task->status;
            $task->update(['status' => $status]);

            $task->history()->create([
                'from_status' => $old,
                'to_status' => $status,
                'changed_by' => $actor->id,
                'reason' => $reason,
            ]);

            $this->audit->record('task', $task->id, 'change_task_status', $actor, ['status' => $old], ['status' => $status], null, $reason);
            $this->events->publish($actor->tenant_id, 'TaskStatusChanged', 'Task', $task->id, ['from' => $old, 'to' => $status]);

            return $task->refresh();
        });
    }

    private function calculateDueAt(int $tenantId, string $module, string $taskType, string $priority): ?Carbon
    {
        $policy = SlaPolicy::query()
            ->where('tenant_id', $tenantId)
            ->where('module', $module)
            ->where('task_type', $taskType)
            ->where('priority', $priority)
            ->first();

        return $policy ? now()->addMinutes($policy->duration_minutes) : null;
    }
}
