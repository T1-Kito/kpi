<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Deal;
use App\Models\Quotation;
use App\Models\Task;
use App\Models\Alert;
use App\Support\DataScope;
use App\Support\LeadService;
use App\Support\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leads, private readonly TaskService $tasks)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min(max((int) $request->query('page_size', 20), 1), 100);
        $scope = $request->validate(['scope' => ['nullable', Rule::in(['all', 'mine', 'unassigned', 'overdue'])]])['scope'] ?? 'all';
        abort_if($scope === 'unassigned' && $request->user()->dataScope() !== 'company', 403);
        $query = Lead::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['assignee:id,name,email', 'nextTask:id,tenant_id,source_id,title,status,due_at,assignee_id']);
        if ($scope !== 'unassigned') DataScope::owned($query, $request->user(), 'assigned_to', 'assignee');

        $visible = clone $query;
        $stats = [
            'all' => (clone $visible)->count(),
            'mine' => (clone $visible)->where('assigned_to', $request->user()->id)->count(),
            'unassigned' => $request->user()->dataScope() === 'company'
                ? Lead::where('tenant_id', $request->user()->tenant_id)->whereNull('assigned_to')->count() : 0,
            'overdue' => (clone $visible)->whereHas('nextTask', fn ($task) => $task->where('due_at', '<', now()))->count(),
        ];

        if ($scope === 'mine' || $request->boolean('mine')) {
            $query->where('assigned_to', $request->user()->id);
        } elseif ($scope === 'unassigned') {
            $query->whereNull('assigned_to');
        } elseif ($scope === 'overdue') {
            $query->whereHas('nextTask', fn ($task) => $task->where('due_at', '<', now()));
        }

        if ($request->filled('status')) $query->where('status', $request->query('status'));

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        $leads = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $leads->items(),
            'meta' => ['page' => $leads->currentPage(), 'page_size' => $leads->perPage(), 'total' => $leads->total(), 'stats' => $stats],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $duplicate = Lead::where('tenant_id', $tenantId)->where('phone', $request->input('phone'))->exists();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:100'],
            'campaign_code' => ['nullable', 'string', 'max:100'],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->where('is_active', true)],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
        ]);
        if ($request->user()->dataScope() !== 'company') {
            abort_if(! empty($data['assigned_to']) && (int) $data['assigned_to'] !== $request->user()->id, 403);
            $data['assigned_to'] = $request->user()->id;
        }
        $data['source'] = $data['source'] ?? 'manual';

        $lead = $this->leads->create($request->user(), $data);

        return response()->json(['data' => $lead->load('assignee:id,name,email'), 'meta' => ['duplicate_phone_warning' => $duplicate]], 201);
    }

    public function show(Request $request, Lead $lead): JsonResponse
    {
        abort_if($lead->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Lead::whereKey($lead->id), $request->user(), 'assigned_to', 'assignee')->exists(), 404);

        return response()->json(['data' => [
            ...$lead->load('assignee:id,name,email')->toArray(),
            'converted_customer' => $lead->customer_id ? \App\Models\Customer::where('tenant_id', $lead->tenant_id)->whereKey($lead->customer_id)->first(['id', 'code', 'name']) : null,
            'converted_deal' => Deal::where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id)->latest('id')->first(['id', 'code', 'name', 'stage']),
            'related_documents' => Quotation::where('tenant_id', $lead->tenant_id)
                ->where('lead_id', $lead->id)
                ->latest('id')
                ->get()
                ->map(fn ($quotation) => [
                    'type' => 'quotation',
                    'label' => 'Báo giá',
                    'code' => $quotation->code,
                    'status' => $quotation->status,
                    'path' => '/quotations/'.$quotation->id,
                ])
                ->all(),
            'tasks' => Task::where('tenant_id', $lead->tenant_id)->where('source_type', 'Lead')->where('source_id', $lead->id)->latest('id')->get(),
            'alerts' => Alert::where('tenant_id', $lead->tenant_id)->where('source_type', 'Lead')->where('source_id', $lead->id)->latest('id')->get(),
            'timeline' => [
                ['label' => 'Tạo khách hàng tiềm năng', 'status' => 'completed', 'at' => $lead->created_at],
                ['label' => 'Phân công chăm sóc', 'status' => $lead->assigned_to ? 'assigned' : 'new', 'at' => $lead->updated_at],
            ],
        ]]);
    }

    public function assign(Request $request, Lead $lead): JsonResponse
    {
        abort_if($lead->tenant_id !== $request->user()->tenant_id, 404);
        abort_if($lead->status === 'qualified', 409, 'Lead đã chuyển thành cơ hội.');
        $data = $request->validate([
            'assigned_to' => ['required', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)->where('is_active', true)],
        ]);
        if ($request->user()->dataScope() !== 'company') {
            abort_unless(($lead->assigned_to === null || $lead->assigned_to === $request->user()->id)
                && (int) $data['assigned_to'] === $request->user()->id, 403);
        }

        $lead = $this->leads->assign($lead, $request->user(), (int) $data['assigned_to']);

        return response()->json(['data' => $lead->load('assignee:id,name,email')]);
    }

    public function createFollowUp(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLeadWork($request, $lead);
        abort_if($lead->status === 'qualified', 409, 'Lead đã chuyển thành cơ hội.');
        abort_if(! $lead->assigned_to, 422, 'Cần phân công lead trước khi tạo công việc chăm sóc.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'due_at' => ['required', 'date', 'after:now'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);

        $task = $this->tasks->create($request->user(), [
            ...$data,
            'module' => 'sales',
            'task_type' => 'lead_follow_up',
            'source_type' => 'Lead',
            'source_id' => $lead->id,
            'assignee_id' => $lead->assigned_to,
        ]);

        return response()->json(['data' => $task], 201);
    }

    public function updateFollowUpStatus(Request $request, Lead $lead, Task $task): JsonResponse
    {
        $this->authorizeLeadWork($request, $lead);
        abort_unless($task->tenant_id === $lead->tenant_id && $task->source_type === 'Lead' && $task->source_id === $lead->id, 404);
        abort_if($lead->status === 'qualified', 409, 'Lead đã chuyển thành cơ hội.');
        abort_if(in_array($task->status, ['completed', 'cancelled'], true), 409, 'Công việc này đã kết thúc.');
        $data = $request->validate([
            'status' => ['required', Rule::in(['in_progress', 'completed'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->tasks->changeStatus($task, $request->user(), $data['status'], $data['reason'] ?? null)]);
    }

    private function authorizeLeadWork(Request $request, Lead $lead): void
    {
        abort_if($lead->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Lead::whereKey($lead->id), $request->user(), 'assigned_to', 'assignee')->exists(), 404);
    }

    public function qualify(Request $request, Lead $lead): JsonResponse
    {
        abort_if($lead->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Lead::whereKey($lead->id), $request->user(), 'assigned_to', 'assignee')->exists(), 404);

        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_type' => ['nullable', 'in:person,organization'],
            'customer_id' => ['nullable', 'integer'],
            'deal_name' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'next_activity_at' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->leads->qualify($lead, $request->user(), $data)]);
    }
}
