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
            ->with(['assignee:id,name,email', 'nextTask:id,tenant_id,source_id,title,status,due_at,assignee_id', 'latestCompletedTask']);
        if ($scope !== 'unassigned') \App\Support\LeadWorkflow::visible($query, $request->user());
        else $query->whereNull('merged_into_id');

        $visible = clone $query;
        $stats = [
            'all' => (clone $visible)->count(),
            'mine' => (clone $visible)->where('assigned_to', $request->user()->id)->count(),
            'unassigned' => $request->user()->dataScope() === 'company'
                ? Lead::where('tenant_id', $request->user()->tenant_id)->whereNull('merged_into_id')->whereNull('assigned_to')->count() : 0,
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

        if ($search = $request->query('q')) \App\Support\VietnameseSearch::apply($query, $search);
        foreach (['source','campaign_code'] as $filter) if ($request->filled($filter)) $query->where($filter,$request->query($filter));

        $leads = $query->latest('id')->paginate($pageSize);
        $editable=DataScope::owned(Lead::whereIn('id',$leads->getCollection()->modelKeys()),$request->user(),'assigned_to','assignee')->pluck('id')->all();
        foreach($leads->items() as $row) $row->setAttribute('can_write',in_array($row->id,$editable,true));

        return response()->json([
            'data' => $leads->items(),
            'meta' => ['page' => $leads->currentPage(), 'page_size' => $leads->perPage(), 'total' => $leads->total(), 'stats' => $stats],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if ($key !== null) {
            abort_if(strlen($key) > 120 || trim($key) === '', 422, 'Mã chống gửi lặp không hợp lệ.');
            $payload = $request->all(); ksort($payload);
            $hash = hash('sha256', json_encode($payload));
            return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $key, $hash) {
                \Illuminate\Support\Facades\DB::table('tenants')->where('id', $request->user()->tenant_id)->lockForUpdate()->first();
                $previous = \Illuminate\Support\Facades\DB::table('request_replays')->where('user_id',$request->user()->id)->where('operation','create_lead')->where('request_key',$key)->first();
                if ($previous) {
                    abort_if($previous->payload_hash !== $hash, 409, 'Mã gửi đã dùng với nội dung khác.');
                    $saved=json_decode($previous->response_body,true);
                    abort_unless(DataScope::owned(Lead::whereKey($saved['data']['id']),$request->user(),'assigned_to','assignee')->exists(),404);
                    return response()->json($saved,$previous->response_status);
                }
                $response=$this->createLead($request);
                if ($response->getStatusCode() >= 400) return $response;
                \Illuminate\Support\Facades\DB::table('request_replays')->insert(['user_id'=>$request->user()->id,'operation'=>'create_lead','request_key'=>$key,'payload_hash'=>$hash,'response_body'=>$response->getContent(),'response_status'=>$response->getStatusCode(),'created_at'=>now(),'updated_at'=>now()]);
                return $response;
            });
        }
        return \Illuminate\Support\Facades\DB::transaction(function() use($request) {
            \App\Models\Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            return $this->createLead($request);
        });
    }

    private function createLead(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            ...$this->needsRules(),
            'allow_duplicate'=>['sometimes','boolean'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50', 'phone_number'],
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

        $duplicates=\App\Support\LeadWorkflow::duplicates($request->user(),$data);
        if ($duplicates && !$request->boolean('allow_duplicate')) return response()->json(['message'=>'Có hồ sơ trùng điện thoại hoặc email. Kiểm tra trước khi lưu.','duplicates'=>$duplicates],409);
        $duplicate=(bool)$duplicates;
        $lead = $this->leads->create($request->user(), $data);

        return response()->json(['data' => $lead->load('assignee:id,name,email'), 'meta' => ['duplicate_phone_warning' => $duplicate]], 201);
    }

    public function show(Request $request, Lead $lead): JsonResponse
    {
        abort_if($lead->tenant_id !== $request->user()->tenant_id, 404);
        if ($lead->merged_into_id) {
            abort_unless(DataScope::owned(Lead::whereKey($lead->id),$request->user(),'assigned_to','assignee')->exists(),404);
            return $this->show($request,Lead::where('tenant_id',$lead->tenant_id)->findOrFail($lead->merged_into_id));
        }
        abort_if(! \App\Support\LeadWorkflow::visible(Lead::whereKey($lead->id)->where('tenant_id',$request->user()->tenant_id), $request->user())->exists(), 404);

        return response()->json(['data' => [
            ...$lead->load('assignee:id,name,email')->toArray(),
            'can_write'=>DataScope::owned(Lead::whereKey($lead->id),$request->user(),'assigned_to','assignee')->exists() && !$lead->merged_into_id,
            'handover_recipient'=> $lead->handover_to ? \App\Models\User::where('tenant_id',$lead->tenant_id)->whereKey($lead->handover_to)->value('name') : null,
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

    public function update(Request $request, Lead $lead): JsonResponse
    {
        return \Illuminate\Support\Facades\DB::transaction(function() use($request,$lead) {
        \App\Models\Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
        $lead=Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
        $this->authorizeLeadWork($request, $lead);
        abort_if($lead->status === 'qualified', 409, 'Hồ sơ đã chuyển đổi. Vui lòng cập nhật tại Khách hàng.');
        abort_if($lead->merged_into_id,409,'Hồ sơ đã gộp.');
        $data = $request->validate([...$this->needsRules(), 'allow_duplicate'=>['sometimes','boolean'], 'name' => ['required','string','max:255'], 'phone' => ['required','string','max:50','phone_number'],
            'email' => ['nullable','email','max:255'], 'source' => ['nullable','string','max:100'], 'campaign_code' => ['nullable','string','max:100']]);
        $duplicates=\App\Support\LeadWorkflow::duplicates($request->user(),$data,$lead->id);
        if ($duplicates && !$request->boolean('allow_duplicate')) return response()->json(['message'=>'Thông tin liên hệ trùng hồ sơ khác. Kiểm tra trước khi lưu.','duplicates'=>$duplicates],409);
        unset($data['allow_duplicate']);
        $before = $lead->only(array_keys($data));
        $lead->workflow_history=\App\Support\LeadWorkflow::history($lead,$request->user(),'Cập nhật yêu cầu',['before'=>$before,'after'=>$data]);
        $lead->update($data);
        if ($lead->handover_status==='pending') \App\Models\VkNotification::create(['tenant_id'=>$lead->tenant_id,'recipient_id'=>$lead->handover_to,'title'=>'Thông tin bàn giao được cập nhật','message'=>$lead->code,'source_type'=>'Lead','source_id'=>$lead->id,'action_url'=>'/leads?open='.$lead->id,'status'=>'unread']);
        app(\App\Support\AuditLogger::class)->record('lead', $lead->id, 'update_lead', $request->user(), $before, $lead->fresh()->only(array_keys($data)));
        return response()->json(['data' => $lead->fresh()]);
        });
    }

    public function assign(Request $request, Lead $lead): JsonResponse
    {
        return \Illuminate\Support\Facades\DB::transaction(function() use($request,$lead) {
        \App\Models\Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
        $lead=Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
        abort_if($lead->tenant_id !== $request->user()->tenant_id, 404);
        abort_if($lead->merged_into_id || $lead->handover_status==='pending',409,'Hồ sơ đã gộp hoặc đang chờ Sales tiếp nhận.');
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
        });
    }

    public function createFollowUp(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLeadWork($request, $lead);
        abort_if($lead->status === 'qualified', 409, 'Lead đã chuyển thành cơ hội.');
        abort_if(! $lead->assigned_to, 422, 'Cần phân công lead trước khi tạo công việc chăm sóc.');

        if (is_string($request->input('due_at')) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/D', $request->input('due_at'))) {
            $request->validate(['due_at' => ['required', 'date']]);
            $request->merge(['due_at' => \Illuminate\Support\Carbon::parse($request->input('due_at'), 'Asia/Ho_Chi_Minh')->utc()->toIso8601String()]);
        }

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
        ], false);

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
        abort_if($lead->merged_into_id,409,'Hồ sơ đã được gộp. Vui lòng mở hồ sơ giữ lại.');
        abort_if($lead->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Lead::whereKey($lead->id), $request->user(), 'assigned_to', 'assignee')->exists(), 404);
    }

    private function needsRules(): array {
        return ['needs'=>['nullable','string','max:4000'],'region'=>['nullable','string','max:255'],
            'expected_service_date'=>['nullable','date_format:Y-m-d'],'guest_count'=>['nullable','integer','min:1','max:1000000'],
            'interested_services'=>['nullable','string','max:2000'],'notes'=>['nullable','string','max:5000']];
    }
    public function duplicates(Request $request): JsonResponse {
        $data=$request->validate(['phone'=>['nullable','string','max:50'],'email'=>['nullable','email','max:255']]);
        return response()->json(['data'=>\App\Support\LeadWorkflow::duplicates($request->user(),$data)]);
    }
    public function handoverCandidates(Request $request): JsonResponse {
        $tenant=$request->user()->tenant;
        $rows=\App\Models\User::where('tenant_id',$tenant->id)->where('is_active',true)->with(['roles.permissions','department'])->orderBy('name')->get()
            ->filter(fn($user)=>\App\Support\LeadHandoverPool::allows($user,$tenant) && $user->id!==$request->user()->id)
            ->map(fn($user)=>['id'=>$user->id,'name'=>$user->name,'department'=>$user->department?->tenant_id===$tenant->id ? $user->department->name : null])->values();
        return response()->json(['data'=>$rows,'meta'=>['configured'=>!empty($tenant->ui_settings['lead_handover']['role_ids'])]]);
    }
    public function handover(Request $request, Lead $lead): JsonResponse {
        $this->authorizeLeadWork($request,$lead);
        $data=$request->validate(['recipient_id'=>['required','integer'],'note'=>['required','string','min:3','max:2000']]);
        return response()->json(['data'=>app(\App\Support\LeadWorkflow::class)->handover($lead,$request->user(),(int)$data['recipient_id'],$data['note'])]);
    }
    public function respondHandover(Request $request, Lead $lead): JsonResponse {
        abort_unless($lead->tenant_id===$request->user()->tenant_id,404);
        $data=$request->validate(['decision'=>['required',Rule::in(['accepted','returned'])],'note'=>['required','string','min:3','max:2000']]);
        return response()->json(['data'=>app(\App\Support\LeadWorkflow::class)->respond($lead,$request->user(),$data['decision'],$data['note'])]);
    }
    public function merge(Request $request, Lead $lead): JsonResponse {
        $this->authorizeLeadWork($request,$lead);
        $data=$request->validate(['target_id'=>['required','integer'],'reason'=>['required','string','min:5','max:2000']]);
        $target=Lead::where('tenant_id',$request->user()->tenant_id)->findOrFail($data['target_id']);
        return response()->json(['data'=>app(\App\Support\LeadWorkflow::class)->merge($lead,$target,$request->user(),$data['reason'])]);
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
