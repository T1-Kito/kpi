<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SlaPolicy;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SlaPolicyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $pageSize = min((int) $request->query('page_size', 50), 100);

        $rows = SlaPolicy::query()
            ->where('tenant_id', $tenantId)
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->query('module')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->query('priority')))
            ->orderBy('module')
            ->orderBy('task_type')
            ->orderBy('priority')
            ->paginate($pageSize);

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'page' => $rows->currentPage(),
                'page_size' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $this->validated($request);

        $policy = SlaPolicy::create($data + ['tenant_id' => $tenantId]);

        $this->audit->record('sla_policy', $policy->id, 'create_sla_policy', $request->user(), null, $policy->toArray(), $request);

        return response()->json(['data' => $policy], 201);
    }

    public function update(Request $request, SlaPolicy $slaPolicy): JsonResponse
    {
        abort_if($slaPolicy->tenant_id !== $request->user()->tenant_id, 404);

        $data = $this->validated($request, $slaPolicy);
        $old = $slaPolicy->toArray();

        $slaPolicy->update($data);

        $this->audit->record('sla_policy', $slaPolicy->id, 'update_sla_policy', $request->user(), $old, $slaPolicy->fresh()->toArray(), $request);

        return response()->json(['data' => $slaPolicy->refresh()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SlaPolicy $policy = null): array
    {
        $tenantId = $request->user()->tenant_id;

        $data=$request->validate([
            'module' => ['required', 'string', 'max:80'],
            'task_type' => [
                'required',
                'string',
                'max:120',
                Rule::unique('sla_policies', 'task_type')
                    ->where('tenant_id', $tenantId)
                    ->where('module', $request->input('module'))
                    ->where('priority', $request->input('priority', 'normal'))
                    ->ignore($policy?->id),
            ],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'warning_before_minutes' => ['required', 'integer', 'min:0', 'max:525600', 'lte:duration_minutes'],
            'escalation_rules' => ['nullable', 'array'],
            'escalation_rules.manager'=>['sometimes','boolean'],
            'escalation_rules.clock'=>['sometimes',Rule::in(['continuous','business'])],
            'escalation_rules.timezone'=>['required_if:escalation_rules.clock,business','timezone'],
            'escalation_rules.weekdays'=>['required_if:escalation_rules.clock,business','array','max:7'],
            'escalation_rules.weekdays.*'=>['integer','between:1,7','distinct'],
            'escalation_rules.periods'=>['required_if:escalation_rules.clock,business','array','max:4'],
            'escalation_rules.periods.*.start'=>['required','date_format:H:i'],
            'escalation_rules.periods.*.end'=>['required','date_format:H:i'],
            'escalation_rules.holidays'=>['sometimes','array','max:100'],
            'escalation_rules.holidays.*'=>['date_format:Y-m-d','distinct'],
            'escalation_rules.allow_pause'=>['sometimes','boolean'],
            'escalation_rules.response_minutes'=>['sometimes','integer','min:1','lte:duration_minutes'],
            'escalation_rules.recipient_ids'=>['sometimes','array','max:20'],
            'escalation_rules.recipient_ids.*'=>['integer','distinct',Rule::exists('users','id')->where('tenant_id',$tenantId)->where('is_active',true)],
        ]);
        if ($data['module']==='service' && $data['task_type']==='ticket') {
            $rules=$data['escalation_rules'] ?? [];
            $rules['weekdays']=array_map('intval',$rules['weekdays'] ?? []);
            \App\Support\ServiceCalendar::validate($rules);
            foreach ($rules['recipient_ids'] ?? [] as $id) {
                $user=\App\Models\User::findOrFail($id);
                abort_unless($user->hasPermission('service.ticket.view') && $user->dataScope()==='company',422,'Người nhận chuyển cấp cần quyền xem ticket toàn doanh nghiệp.');
            }
            $data['escalation_rules']=$rules;
        }
        return $data;
    }
}
