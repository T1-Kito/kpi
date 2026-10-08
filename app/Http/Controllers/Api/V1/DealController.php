<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Quotation;
use App\Models\Task;
use App\Support\TaskService;
use App\Support\DataScope;
use App\Support\DealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealController extends Controller
{
    public function __construct(private readonly DealService $deals, private readonly TaskService $tasks) {}

    public function index(Request $request): JsonResponse
    {
        $query = Deal::where('tenant_id', $request->user()->tenant_id)
            ->with(['customer:id,code,name', 'lead:id,code,name', 'owner:id,name']);
        DataScope::owned($query, $request->user(), 'owner_id', 'owner');
        if ($status = $request->query('status')) $query->where('status', $status);
        if ($stage = $request->query('stage')) $query->where('stage', $stage);
        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }
        $rows = $query->latest('id')->paginate(min((int) $request->query('page_size', 50), 100));
        return response()->json(['data' => $rows->items(), 'meta' => ['page' => $rows->currentPage(), 'page_size' => $rows->perPage(), 'total' => $rows->total(), 'stages' => DealService::stages((int) $request->user()->tenant_id)]]);
    }

    public function show(Request $request, Deal $deal): JsonResponse
    {
        abort_if($deal->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Deal::whereKey($deal->id), $request->user(), 'owner_id', 'owner')->exists(), 404);
        $deal->load(['customer:id,code,name,contact_name,phone,email', 'lead:id,code,name,phone,email', 'owner:id,name,email', 'stageHistory.changedBy:id,name', 'items.sku:id,sku_code,name,unit']);
        $quotations = Quotation::where('tenant_id', $deal->tenant_id)->where('deal_id', $deal->id)->latest('id')->get(['id', 'code', 'status', 'total_amount']);
        return response()->json(['data' => [...$deal->toArray(),
            'related_documents' => $quotations->map(fn ($item) => ['type' => 'quotation', 'label' => 'Báo giá', 'code' => $item->code, 'status' => $item->status, 'path' => '/quotations/'.$item->id])->all(),
            'tasks' => Task::where('tenant_id', $deal->tenant_id)->where('source_type', 'Deal')->where('source_id', $deal->id)->with('assignee:id,name')->latest('id')->get(),
            'timeline' => $deal->stageHistory->map(fn ($item) => ['label' => 'Chuyển từ '.($item->from_stage ?: 'Khởi tạo').' sang '.$item->to_stage, 'status' => $item->to_stage, 'at' => $item->changed_at])->all(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'lead_id' => ['nullable', Rule::exists('leads', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'owner_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'next_activity_at' => ['nullable', 'date'],
            'stage' => ['nullable', Rule::in(array_keys(DealService::stages((int) $tenantId)))],
        ]);
        return response()->json(['data' => $this->deals->create($request->user(), $data)->load(['customer:id,code,name', 'lead:id,code,name', 'owner:id,name'])], 201);
    }

    public function transition(Request $request, Deal $deal): JsonResponse
    {
        abort_if($deal->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Deal::whereKey($deal->id), $request->user(), 'owner_id', 'owner')->exists(), 404);
        $data = $request->validate(['stage' => ['required', Rule::in(array_keys(DealService::stages((int) $deal->tenant_id)))], 'reason' => ['nullable', 'string', 'max:1000'], 'version' => ['required', 'integer', 'min:1']]);
        return response()->json(['data' => $this->deals->transition($deal, $request->user(), $data['stage'], $data['reason'] ?? null, $data['version'])->load(['customer:id,code,name', 'lead:id,code,name', 'owner:id,name'])]);
    }

    public function syncItems(Request $request, Deal $deal): JsonResponse
    {
        abort_if($deal->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['items' => ['array'], 'items.*.sku_id' => [Rule::exists('skus', 'id')->where('tenant_id', $deal->tenant_id)], 'items.*.quantity' => ['numeric', 'gt:0'], 'items.*.unit_price' => ['numeric', 'min:0']]);
        return response()->json(['data' => $this->deals->syncItems($deal, $request->user(), $data['items'] ?? [])->load('items.sku:id,sku_code,name,unit')]);
    }

    public function addActivity(Request $request, Deal $deal): JsonResponse
    {
        abort_if($deal->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'due_at' => ['nullable', 'date'], 'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])]]);
        $task = $this->tasks->create($request->user(), ['module' => 'sales', 'task_type' => 'deal_follow_up', 'priority' => $data['priority'] ?? 'normal', 'title' => $data['title'], 'description' => $data['description'] ?? null, 'source_type' => 'Deal', 'source_id' => $deal->id, 'assignee_id' => $deal->owner_id, 'due_at' => $data['due_at'] ?? null]);
        return response()->json(['data' => $task->load('assignee:id,name')], 201);
    }
}
