<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\KpiAssignment;
use App\Models\KpiBundle;
use App\Models\KpiBundlePlan;
use App\Models\KpiDefinition;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Kpi\AssignedKpiService;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KpiBundleController extends Controller
{
    public function __construct(private AssignedKpiService $assigned, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        return response()->json(['data' => KpiBundle::where('tenant_id', $request->user()->tenant_id)
            ->with(['items.definition:id,code,name,status', 'plans'])->orderBy('code')->orderByDesc('version')->get()]);
    }

    public function store(Request $request)
    {
        return $this->save($request, null);
    }

    public function update(Request $request, KpiBundle $kpiBundle)
    {
        $this->sameTenant($request, $kpiBundle);
        abort_if($kpiBundle->status !== 'draft', 422, 'Bộ KPI đã phát hành không thể sửa. Hãy tạo phiên bản mới.');

        return $this->save($request, $kpiBundle);
    }

    private function save(Request $request, ?KpiBundle $bundle)
    {
        $tenant = (int) $request->user()->tenant_id;
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.kpi_definition_id' => ['required', 'integer', 'distinct', Rule::exists('kpi_definitions', 'id')->where('tenant_id', $tenant)->where('status', 'active')],
            'items.*.metric' => ['required', Rule::in(array_keys(AssignedKpiService::METRICS))],
            'items.*.target_value' => ['required', 'numeric', 'min:0.01', 'max:9999999999999999'],
            'items.*.weight' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'items.*.direction' => ['required', Rule::in(['increase', 'decrease'])],
        ]);
        $data['code'] = strtoupper($data['code']);
        if ($bundle) $data['code'] = $bundle->code;
        $weight = round(collect($data['items'])->sum(fn ($item) => (float) $item['weight']), 2);
        if (abs($weight - 100) > 0.001) {
            throw ValidationException::withMessages(['items' => 'Tổng trọng số của bộ KPI phải đúng 100%. Hiện tại: '.$weight.'%.']);
        }

        return DB::transaction(function () use ($request, $tenant, $data, $bundle) {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            if ($bundle) $bundle = KpiBundle::whereKey($bundle->id)->lockForUpdate()->firstOrFail();
            $version = $bundle?->version ?? ((int) KpiBundle::where('tenant_id', $tenant)->where('code', $data['code'])->max('version') + 1);
            $duplicate = KpiBundle::where('tenant_id', $tenant)->where('code', $data['code'])->where('version', $version);
            if ($bundle) $duplicate->where('id', '!=', $bundle->id);
            if ($duplicate->exists()) throw ValidationException::withMessages(['code' => 'Mã và phiên bản bộ KPI đã tồn tại.']);
            $before = $bundle?->load('items')->toArray();
            $bundle ??= new KpiBundle(['tenant_id' => $tenant, 'created_by' => $request->user()->id, 'version' => $version, 'status' => 'draft']);
            $bundle->fill(['code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null])->save();
            $bundle->items()->delete();
            foreach (array_values($data['items']) as $index => $item) {
                $bundle->items()->create($item + ['sort_order' => $index + 1]);
            }
            $after = $bundle->fresh()->load('items.definition:id,code,name,status');
            $this->audit->record('kpi_bundle', $bundle->id, $before ? 'update_bundle' : 'create_bundle', $request->user(), $before, $after->toArray(), $request);

            return response()->json(['data' => $after], $before ? 200 : 201);
        });
    }

    public function publish(Request $request, KpiBundle $kpiBundle)
    {
        $this->sameTenant($request, $kpiBundle);
        return DB::transaction(function () use ($request, $kpiBundle) {
            Tenant::whereKey($kpiBundle->tenant_id)->lockForUpdate()->firstOrFail();
            $bundle = KpiBundle::whereKey($kpiBundle->id)->lockForUpdate()->with('items.definition')->firstOrFail();
            if ($bundle->status === 'active') return response()->json(['data' => $bundle]);
            abort_if($bundle->status !== 'draft', 422, 'Chỉ có thể phát hành bản nháp.');
            abort_if($bundle->items->isEmpty(), 422, 'Bộ KPI chưa có chỉ tiêu.');
            abort_if(abs((float) $bundle->items->sum('weight') - 100) > 0.001, 422, 'Tổng trọng số phải đúng 100%.');
            abort_if($bundle->items->contains(fn ($item) => $item->definition?->status !== 'active'), 422, 'Bộ KPI chứa chỉ tiêu đã ngừng sử dụng.');
            $before = $bundle->toArray();
            $previous = KpiBundle::where('tenant_id', $bundle->tenant_id)->where('code', $bundle->code)->where('id', '!=', $bundle->id)->where('status', 'active')->pluck('id');
            KpiBundle::whereIn('id', $previous)->update(['status' => 'inactive']);
            KpiBundlePlan::whereIn('kpi_bundle_id', $previous)->where('status', 'active')->update(['status' => 'inactive']);
            $bundle->update(['status' => 'active', 'published_at' => now()]);
            $this->audit->record('kpi_bundle', $bundle->id, 'publish_bundle', $request->user(), $before, $bundle->fresh()->toArray(), $request);
            return response()->json(['data' => $bundle->fresh()->load(['items.definition:id,code,name,status', 'plans'])]);
        });
    }

    public function duplicate(Request $request, KpiBundle $kpiBundle)
    {
        $this->sameTenant($request, $kpiBundle);
        return DB::transaction(function () use ($request, $kpiBundle) {
            Tenant::whereKey($kpiBundle->tenant_id)->lockForUpdate()->firstOrFail();
            $source = KpiBundle::whereKey($kpiBundle->id)->with('items')->firstOrFail();
            $version = (int) KpiBundle::where('tenant_id', $source->tenant_id)->where('code', $source->code)->max('version') + 1;
            $copy = KpiBundle::create(['tenant_id' => $source->tenant_id, 'code' => $source->code, 'name' => $source->name,
                'description' => $source->description, 'version' => $version, 'status' => 'draft', 'created_by' => $request->user()->id]);
            foreach ($source->items as $item) $copy->items()->create($item->only(['kpi_definition_id', 'metric', 'target_value', 'weight', 'direction', 'sort_order']));
            $this->audit->record('kpi_bundle', $copy->id, 'duplicate_bundle', $request->user(), ['source_id' => $source->id], $copy->toArray(), $request);
            return response()->json(['data' => $copy->load('items.definition:id,code,name,status')], 201);
        });
    }

    public function apply(Request $request, KpiBundle $kpiBundle)
    {
        $this->sameTenant($request, $kpiBundle);
        $tenant = (int) $request->user()->tenant_id;
        $data = $request->validate([
            'audience_type' => ['required', Rule::in(['company', 'department', 'position', 'user'])],
            'audience_id' => ['nullable', 'integer', 'min:0'],
            'period_type' => ['required', Rule::in(['month', 'quarter', 'year'])],
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['draft', 'active'])],
            'recurring' => ['nullable', 'boolean'],
        ]);
        $data['audience_id'] = $data['audience_type'] === 'company' ? 0 : ($data['audience_id'] ?? 0);
        $class = match ($data['audience_type']) {'user' => User::class, 'department' => Department::class, 'position' => Position::class, default => null};
        if ($class) {
            $audienceQuery = $class::where('tenant_id', $tenant)->whereKey($data['audience_id']);
            if ($class === User::class) $audienceQuery->where('is_active', true);
            if (! $audienceQuery->exists()) throw ValidationException::withMessages(['audience_id' => 'Người/nhóm phải thuộc doanh nghiệp và đang sử dụng.']);
        }
        $date = CarbonImmutable::parse($data['date']);
        [$start, $end] = match ($data['period_type']) {'quarter' => [$date->startOfQuarter(), $date->endOfQuarter()], 'year' => [$date->startOfYear(), $date->endOfYear()], default => [$date->startOfMonth(), $date->endOfMonth()]};

        return DB::transaction(function () use ($request, $kpiBundle, $tenant, $data, $start, $end) {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $bundle = KpiBundle::whereKey($kpiBundle->id)->lockForUpdate()->with('items.definition')->firstOrFail();
            abort_if($bundle->status !== 'active', 422, 'Chỉ áp dụng bộ KPI đã phát hành.');
            $probe = new KpiAssignment(['tenant_id' => $tenant, 'audience_type' => $data['audience_type'], 'audience_id' => $data['audience_id']]);
            $members = $this->assigned->audience($probe)->pluck('id')->map(fn ($id) => (string) $id)->all();
            if (! $members) throw ValidationException::withMessages(['audience_id' => 'Nhóm hiện chưa có nhân viên hoạt động nên chưa thể giao KPI.']);
            $definitionIds = $bundle->items->pluck('kpi_definition_id');
            $existing = KpiAssignment::where('tenant_id', $tenant)->whereIn('kpi_definition_id', $definitionIds)
                ->where('audience_type', $data['audience_type'])->where('audience_id', $data['audience_id'])
                ->where('period_type', $data['period_type'])->whereDate('period_start', $start)->exists();
            if ($existing) throw ValidationException::withMessages(['date' => 'Nhóm đã có một hoặc nhiều chỉ tiêu của bộ trong kỳ này. Không tạo bản trùng.']);
            $locked = KpiAssignment::where('tenant_id', $tenant)->whereIn('kpi_definition_id', $definitionIds)
                ->where('period_type', $data['period_type'])->whereDate('period_start', $start)->whereNotNull('locked_at')->get();
            foreach ($locked as $closed) {
                if (array_intersect($members, array_keys($closed->locked_results ?? []))) throw ValidationException::withMessages(['date' => 'Có nhân viên đã chốt một chỉ tiêu thuộc bộ trong kỳ này.']);
            }
            $created = [];
            foreach ($bundle->items as $item) {
                $created[] = KpiAssignment::create(['tenant_id' => $tenant, 'kpi_definition_id' => $item->kpi_definition_id, 'kpi_bundle_id' => $bundle->id,
                    'audience_type' => $data['audience_type'], 'audience_id' => $data['audience_id'], 'period_type' => $data['period_type'],
                    'period_start' => $start, 'period_end' => $end, 'metric' => $item->metric, 'target_value' => $item->target_value,
                    'weight' => $item->weight, 'direction' => $item->direction, 'status' => $data['status'], 'created_by' => $request->user()->id]);
            }
            if (! empty($data['recurring'])) {
                KpiBundlePlan::updateOrCreate(['tenant_id' => $tenant, 'kpi_bundle_id' => $bundle->id, 'audience_type' => $data['audience_type'],
                    'audience_id' => $data['audience_id'], 'period_type' => $data['period_type']],
                    ['assignment_status' => $data['status'], 'starts_on' => $start, 'status' => 'active', 'created_by' => $request->user()->id]);
            }
            $this->audit->record('kpi_bundle', $bundle->id, 'apply_bundle', $request->user(), null,
                ['assignment_ids' => collect($created)->pluck('id')->all(), 'audience_type' => $data['audience_type'], 'audience_id' => $data['audience_id'], 'period_start' => $start->toDateString()], $request);
            return response()->json(['data' => collect($created)->map->load('definition:id,code,name'), 'meta' => ['employees' => count($members), 'assignments' => count($created), 'recurring' => ! empty($data['recurring'])]], 201);
        });
    }

    private function sameTenant(Request $request, KpiBundle $bundle): void
    {
        abort_if($bundle->tenant_id !== $request->user()->tenant_id, 404);
    }
}
