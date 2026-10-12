<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\KpiAssignment;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Kpi\AssignedKpiService;
use App\Services\Kpi\KpiBundleRolloutService;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KpiAssignmentController extends Controller
{
    public function __construct(private AssignedKpiService $service, private AuditLogger $audit, private KpiBundleRolloutService $rollout) {}

    public function mine(Request $request)
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = CarbonImmutable::parse($input['date'] ?? now()->toDateString());
        $this->rollout->ensureTenant((int) $request->user()->tenant_id, $date);
        $rows = $this->service->personal($request->user(), $date->toDateString());
        $summaries = collect($rows)->groupBy(fn ($row) => $row['period_type'].'-'.$row['period_start'])->map(function ($group) {
            $weight = $group->sum('weight');

            return ['period_type' => $group->first()['period_type'], 'period_start' => $group->first()['period_start'],
                'period_end' => $group->first()['period_end'], 'score' => round($group->sum(fn ($row) => $row['score'] * $row['weight']) / $weight, 2),
                'indicators' => $group->count(), 'locked' => $group->every(fn ($row) => $row['status'] === 'locked')];
        })->values();

        return response()->json(['data' => $rows,
            'meta' => ['as_of' => now()->toIso8601String(), 'user_id' => $request->user()->id, 'summaries' => $summaries]]);
    }

    public function index(Request $request)
    {
        $tenant = $request->user()->tenant_id;
        $this->rollout->ensureTenant((int) $tenant, CarbonImmutable::today());

        return response()->json(['data' => KpiAssignment::where('tenant_id', $tenant)->with('definition:id,name,code')->latest('period_start')->latest('id')->get(),
            'meta' => ['metrics' => AssignedKpiService::METRICS,
                'users' => User::where('tenant_id', $tenant)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'department_id', 'position_id']),
                'departments' => Department::where('tenant_id', $tenant)->orderBy('name')->get(['id', 'name']),
                'positions' => Position::where('tenant_id', $tenant)->orderBy('name')->get(['id', 'name'])]]);
    }

    public function store(Request $request)
    {
        return $this->save($request, null);
    }

    public function preview(Request $request)
    {
        $tenant = $request->user()->tenant_id;
        $data = $request->validate([
            'audience_type' => ['required', Rule::in(['company', 'department', 'position', 'user'])],
            'audience_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $this->validateAudience($tenant, $data);
        $assignment = new KpiAssignment($data + ['tenant_id' => $tenant]);
        $members = $this->service->audience($assignment)->orderBy('name')
            ->with(['department:id,name', 'position:id,name'])->get(['id', 'name', 'department_id', 'position_id']);

        return response()->json(['data' => $members, 'meta' => [
            'total' => $members->count(),
            'missing_department' => $members->whereNull('department_id')->count(),
            'missing_position' => $members->whereNull('position_id')->count(),
            'membership' => 'current_active',
        ]]);
    }

    private function validateAudience(int $tenant, array &$data): void
    {
        $data['audience_id'] = $data['audience_type'] === 'company' ? 0 : ($data['audience_id'] ?? 0);
        $class = match ($data['audience_type']) {
            'user' => User::class, 'department' => Department::class, 'position' => Position::class, default => null,
        };
        if (! $class) return;
        $query = $class::where('tenant_id', $tenant)->where('id', $data['audience_id']);
        if ($class === User::class) $query->where('is_active', true);
        if (! $query->exists()) {
            throw ValidationException::withMessages(['audience_id' => 'Chọn người/nhóm thuộc doanh nghiệp hiện tại; tài khoản phải đang hoạt động.']);
        }
    }

    public function update(Request $request, KpiAssignment $kpiAssignment)
    {
        abort_if($kpiAssignment->tenant_id !== $request->user()->tenant_id, 404);

        return $this->save($request, $kpiAssignment);
    }

    private function save(Request $request, ?KpiAssignment $assignment)
    {
        $tenant = $request->user()->tenant_id;
        $data = $request->validate([
            'kpi_definition_id' => ['required', 'integer', Rule::exists('kpi_definitions', 'id')->where('tenant_id', $tenant)->where('status', 'active')],
            'audience_type' => ['required', Rule::in(['company', 'department', 'position', 'user'])],
            'audience_id' => ['nullable', 'integer', 'min:0'],
            'period_type' => ['required', Rule::in(['month', 'quarter', 'year'])],
            'date' => ['required', 'date_format:Y-m-d'],
            'metric' => ['required', Rule::in(array_keys(AssignedKpiService::METRICS))],
            'target_value' => ['required', 'numeric', 'min:0.01', 'max:9999999999999999'],
            'weight' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'direction' => ['required', Rule::in(['increase', 'decrease'])],
            'status' => ['required', Rule::in(['draft', 'active', 'inactive'])],
        ]);
        $this->validateAudience($tenant, $data);
        $date = CarbonImmutable::parse($data['date']);
        [$start, $end] = match ($data['period_type']) {
            'quarter' => [$date->startOfQuarter(), $date->endOfQuarter()],
            'year' => [$date->startOfYear(), $date->endOfYear()],
            default => [$date->startOfMonth(), $date->endOfMonth()],
        };
        unset($data['date']);
        $data += ['tenant_id' => $tenant, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString()];

        // Serialise save and lock operations on the same tenant to prevent duplicate assignments and lost locks.
        return DB::transaction(function () use ($assignment, $data, $request, $tenant) {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $record = $assignment ? KpiAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail() : new KpiAssignment;
            abort_if($record->locked_at, 422, 'KPI đã chốt kỳ, không thể sửa.');
            $prospective = new KpiAssignment($data);
            $members = $this->service->audience($prospective)->pluck('id')->map(fn ($id) => (string) $id)->all();
            $locked = KpiAssignment::where('tenant_id', $tenant)->where('kpi_definition_id', $data['kpi_definition_id'])
                ->where('period_type', $data['period_type'])->whereDate('period_start', $data['period_start'])->whereNotNull('locked_at')->get();
            foreach ($locked as $closed) {
                if (array_intersect($members, array_keys($closed->locked_results ?? []))) {
                    throw ValidationException::withMessages(['date' => 'Nhóm có nhân viên đã chốt chỉ tiêu này trong kỳ. Không thể phân đè kết quả đã chốt.']);
                }
            }
            $duplicates = KpiAssignment::where('tenant_id', $tenant)->where('kpi_definition_id', $data['kpi_definition_id'])
                ->where('audience_type', $data['audience_type'])->where('audience_id', $data['audience_id'])
                ->where('period_type', $data['period_type'])->whereDate('period_start', $data['period_start']);
            if ($record->exists) {
                $duplicates->where('id', '!=', $record->id);
            }
            if ($duplicates->exists()) {
                throw ValidationException::withMessages(['kpi_definition_id' => 'Chỉ tiêu đã được phân cho nhóm này trong kỳ. Hãy sửa bản phân công hiện có.']);
            }
            $before = $record->exists ? $record->toArray() : null;
            if (! $record->exists) {
                $record->created_by = $request->user()->id;
            }
            $record->fill($data)->save();
            $this->audit->record('kpi_assignment', $record->id, $before ? 'update_assignment' : 'create_assignment', $request->user(), $before, $record->toArray(), $request);

            return response()->json(['data' => $record->load('definition:id,name,code')], $before ? 200 : 201);
        });
    }

    public function lock(Request $request, KpiAssignment $kpiAssignment)
    {
        abort_if($kpiAssignment->tenant_id !== $request->user()->tenant_id, 404);

        return DB::transaction(function () use ($request, $kpiAssignment) {
            Tenant::whereKey($kpiAssignment->tenant_id)->lockForUpdate()->firstOrFail();
            $assignment = KpiAssignment::whereKey($kpiAssignment->id)->lockForUpdate()->with('definition:id,name,code')->firstOrFail();
            if ($assignment->locked_at) {
                return response()->json(['data' => $assignment]);
            }
            abort_if($assignment->status !== 'active', 422, 'Chỉ chốt KPI đang theo dõi.');
            abort_if($assignment->period_end->endOfDay()->isFuture(), 422, 'Kỳ chưa kết thúc, chưa thể chốt kết quả.');
            $before = $assignment->toArray();
            $results = [];
            foreach ($this->service->audience($assignment)->get() as $user) {
                $results[(string) $user->id] = $this->service->evaluate($assignment, $user);
            }
            $assignment->update(['locked_results' => $results, 'locked_at' => now(), 'status' => 'locked']);
            $this->audit->record('kpi_assignment', $assignment->id, 'lock_assignment', $request->user(), $before, $assignment->toArray(), $request);

            return response()->json(['data' => $assignment]);
        });
    }
}
