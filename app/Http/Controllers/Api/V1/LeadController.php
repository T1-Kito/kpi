<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Task;
use App\Models\Alert;
use App\Support\DataScope;
use App\Support\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leads)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = Lead::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('assignee:id,name,email');
        DataScope::owned($query, $request->user(), 'assigned_to', 'assignee');

        if ($request->boolean('mine')) {
            $query->where('assigned_to', $request->user()->id);
        }

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }

        $leads = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $leads->items(),
            'meta' => ['page' => $leads->currentPage(), 'page_size' => $leads->perPage(), 'total' => $leads->total()],
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
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
        ]);

        $lead = $this->leads->create($request->user(), $data);

        return response()->json(['data' => $lead->load('assignee:id,name,email'), 'meta' => ['duplicate_phone_warning' => $duplicate]], 201);
    }

    public function show(Request $request, Lead $lead): JsonResponse
    {
        abort_if($lead->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Lead::whereKey($lead->id), $request->user(), 'assigned_to', 'assignee')->exists(), 404);

        return response()->json(['data' => [
            ...$lead->load('assignee:id,name,email')->toArray(),
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
        $data = $request->validate([
            'assigned_to' => ['required', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        $lead = $this->leads->assign($lead, $request->user(), (int) $data['assigned_to']);

        return response()->json(['data' => $lead->load('assignee:id,name,email')]);
    }
}
