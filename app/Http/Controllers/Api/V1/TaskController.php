<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Support\DataScope;
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
        $pageSize = min(max((int) $request->query('page_size', 20),1), 100);
        $query = Task::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['assignee:id,name,email', 'department:id,code,name']);
        DataScope::task($query, $request->user());
        $base=clone $query;
        $today=now('Asia/Ho_Chi_Minh')->startOfDay()->utc();$tomorrow=$today->copy()->addDay();
        $week=now('Asia/Ho_Chi_Minh')->startOfWeek()->utc();$weekEnd=$week->copy()->addWeek();
        $active=fn($q)=>$q->whereNotIn('status',['completed','cancelled']);
        $stats=['all'=>(clone $base)->count(),'completed'=>(clone $base)->where('status','completed')->count(),
            'pending'=>$active(clone $base)->count(),'overdue'=>$active(clone $base)->where('due_at','<',now())->count(),
            'today'=>(clone $base)->where('due_at','>=',$today)->where('due_at','<',$tomorrow)->count(),
            'week'=>(clone $base)->where('due_at','>=',$week)->where('due_at','<',$weekEnd)->count()];
        $assignees=(clone $base)->get(['assignee_id'])->pluck('assignee_id')->filter()->unique();
        $owners=\App\Models\User::where('tenant_id',$request->user()->tenant_id)->whereIn('id',$assignees)->orderBy('name')->get(['id','name']);
        foreach(['priority','assignee_id'] as $field)if($request->filled($field))$query->where($field,$request->query($field));
        $applyDue=function($q,$filter)use($active,$today,$tomorrow,$week,$weekEnd){
            if($filter==='overdue')$active($q)->where('due_at','<',now());
            elseif($filter==='today')$q->where('due_at','>=',$today)->where('due_at','<',$tomorrow);
            elseif($filter==='week')$q->where('due_at','>=',$week)->where('due_at','<',$weekEnd);
            elseif($filter==='none')$q->whereNull('due_at');
        };
        $tab=$request->query('tab','all');
        if($tab==='completed')$query->where('status','completed');
        elseif($tab==='pending')$active($query);
        else $applyDue($query,$tab);
        $applyDue($query,$request->query('due'));

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
            'meta' => ['page' => $tasks->currentPage(), 'page_size' => $tasks->perPage(), 'total' => $tasks->total(),'last_page'=>$tasks->lastPage(),'stats'=>$stats,'assignees'=>$owners],
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

        $task = $this->tasks->create($request->user(), $data, false);

        return response()->json(['data' => $task->load(['assignee:id,name,email', 'department:id,code,name'])], 201);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        abort_if($task->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::task(Task::whereKey($task->id), $request->user())->exists(), 404);

        return response()->json([
            'data' => $task->load(['assignee:id,name,email', 'department:id,code,name', 'history']),
        ]);
    }

    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        abort_if($task->tenant_id !== $request->user()->tenant_id, 404);
        abort_unless(DataScope::task(Task::whereKey($task->id), $request->user())->exists(), 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['new', 'in_progress', 'completed', 'overdue', 'cancelled'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        abort_if($task->task_type === 'customer_follow_up' && $data['status'] === 'completed' && !trim($data['reason'] ?? ''),422,'Cần ghi kết quả chăm sóc trước khi hoàn thành.');

        $task = $this->tasks->changeStatus($task, $request->user(), $data['status'], $data['reason'] ?? null);

        return response()->json(['data' => $task->load(['assignee:id,name,email', 'department:id,code,name'])]);
    }
}
