<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Support\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = Task::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['assignee:id,name,email', 'department:id,code,name']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('mine')) {
            $query->where('assignee_id', $request->user()->id);
        }

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%"));
        }

        $tasks = $query->orderByRaw("case when status = 'overdue' then 0 else 1 end")
            ->orderBy('due_at')
            ->latest('id')
            ->paginate($pageSize);

        return response()->json([
            'data' => $tasks->items(),
            'meta' => ['page' => $tasks->currentPage(), 'page_size' => $tasks->perPage(), 'total' => $tasks->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'module' => ['nullable', 'string', 'max:50'],
            'task_type' => ['required', 'string', 'max:50'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'assignee_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('tenant_id', $tenantId)],
            'due_at' => ['nullable', 'date'],
        ]);

        $task = $this->tasks->create($request->user(), $data);

        return response()->json(['data' => $task->load(['assignee:id,name,email', 'department:id,code,name'])], 201);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        abort_if($task->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json([
            'data' => $task->load(['assignee:id,name,email', 'department:id,code,name', 'history']),
        ]);
    }

    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        abort_if($task->tenant_id !== $request->user()->tenant_id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['new', 'in_progress', 'completed', 'overdue', 'cancelled'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $task = $this->tasks->changeStatus($task, $request->user(), $data['status'], $data['reason'] ?? null);

        return response()->json(['data' => $task->load(['assignee:id,name,email', 'department:id,code,name'])]);
    }
}
