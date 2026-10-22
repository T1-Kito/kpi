<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KpiAdjustment;
use App\Models\KpiDefinition;
use App\Models\KpiException;
use App\Models\KpiScoreSnapshot;
use App\Models\KpiTarget;
use App\Models\User;
use App\Services\Kpi\KpiService;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KpiController extends Controller
{
    public function __construct(
        private readonly KpiService $kpis,
        private readonly AuditLogger $audit,
        private readonly CodeGenerator $codes,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        abort_unless($request->user()->dataScope() === 'company', 403, 'Tổng quan toàn công ty cần quyền phạm vi toàn công ty.');
        return response()->json(['data' => $this->kpis->overview($request->user()->tenant_id)]);
    }

    public function employeeReport(Request $request, \App\Services\Kpi\EmployeeKpiReport $reports): JsonResponse
    {
        $report = $reports->build($request->user(), $this->reportFilters($request));
        $report['meta']['can_export'] = $request->user()->hasPermission('kpi.report.export');
        return response()->json(['data' => $report['rows'], 'meta' => $report['meta'], 'employees' => $report['employees']]);
    }

    public function exportEmployeeReport(Request $request, \App\Services\Kpi\EmployeeKpiReport $reports, \App\Services\Kpi\KpiReportWorkbook $workbooks)
    {
        abort_unless($request->user()->hasPermission('kpi.report.export'), 403, 'Bạn có thể xem báo cáo nhưng chưa được cấp quyền xuất Excel KPI.');
        $report = $reports->build($request->user(), $this->reportFilters($request));
        $path = $workbooks->write($report);
        try {
            $this->audit->record('kpi_report', null, 'export_kpi_report', $request->user(), null, [
                'filters' => $this->reportFilters($request), 'scope' => $report['meta']['scope'], 'period_start' => $report['meta']['period_start'], 'period_end' => $report['meta']['period_end'],
                'employee_count' => count($report['rows']), 'kpi_count' => count($report['kpis']), 'task_count' => count($report['tasks']), 'adjustment_count' => count($report['adjustments']),
            ], $request);
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
        return response()->download($path, 'KPI-nhan-vien-'.$report['meta']['period_start'].'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    private function reportFilters(Request $request): array
    {
        return $request->validate([
            'period_type' => ['nullable', Rule::in(['month', 'quarter', 'year'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    public function snapshots(Request $request): JsonResponse
    {
        abort_unless($request->user()->dataScope() === 'company', 403, 'Lịch sử KPI công ty cần quyền phạm vi toàn công ty.');
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = KpiScoreSnapshot::where('tenant_id', $request->user()->tenant_id)
            ->latest('snapshot_date')
            ->latest('id');

        if ($periodType = $request->query('period_type')) {
            $query->where('period_type', $periodType);
        }

        $snapshots = $query->paginate($pageSize);

        return response()->json([
            'data' => $snapshots->items(),
            'meta' => [
                'page' => $snapshots->currentPage(),
                'page_size' => $snapshots->perPage(),
                'total' => $snapshots->total(),
            ],
        ]);
    }

    public function calculate(Request $request): JsonResponse
    {
        $snapshot = $this->kpis->calculateSnapshot($request->user()->tenant, $request->input('period_type', 'day'));
        $this->audit->record(
            'kpi_score_snapshot',
            $snapshot->id,
            'calculate_kpi_snapshot',
            $request->user(),
            null,
            $snapshot->only(['snapshot_date', 'period_type', 'overall_score', 'status']),
            $request,
        );

        return response()->json(['data' => $snapshot], 201);
    }

    public function targets(Request $request): JsonResponse
    {
        $targets = KpiTarget::where('tenant_id', $request->user()->tenant_id)
            ->with('definition:id,code,name')
            ->latest('period_start')
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $targets]);
    }

    public function definitions(Request $request): JsonResponse
    {
        $definitions = KpiDefinition::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('status')
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $definitions]);
    }

    public function storeDefinition(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'source_type' => ['nullable', 'string', 'max:100'],
            'formula' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'target_direction' => ['required', Rule::in(['increase', 'decrease'])],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $definition = KpiDefinition::create($data + [
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('kpi_definitions', 'code', 'KPI-', fn ($query) => $query->where('tenant_id', $tenantId)),
        ]);
        $this->audit->record('kpi_definition', $definition->id, 'create_kpi_definition', $request->user(), null, $definition->toArray(), $request);

        return response()->json(['data' => $definition], 201);
    }

    public function updateDefinition(Request $request, KpiDefinition $kpiDefinition): JsonResponse
    {
        abort_if($kpiDefinition->tenant_id !== $request->user()->tenant_id, 404);

        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'source_type' => ['nullable', 'string', 'max:100'],
            'formula' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'target_direction' => ['required', Rule::in(['increase', 'decrease'])],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $old = $kpiDefinition->toArray();
        $kpiDefinition->update($data);
        $this->audit->record('kpi_definition', $kpiDefinition->id, 'update_kpi_definition', $request->user(), $old, $kpiDefinition->fresh()->toArray(), $request);

        return response()->json(['data' => $kpiDefinition->refresh()]);
    }

    public function storeTarget(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'kpi_definition_id' => ['required', Rule::exists('kpi_definitions', 'id')->where('tenant_id', $tenantId)],
            'period_type' => ['required', Rule::in(['day', 'week', 'month', 'quarter', 'year'])],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'target_value' => ['required', 'numeric', 'min:0'],
            'actual_value' => ['nullable', 'numeric', 'min:0'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['required', Rule::in(['draft', 'active', 'locked'])],
        ]);

        $target = KpiTarget::create($data + [
            'tenant_id' => $tenantId,
            'actual_value' => $data['actual_value'] ?? 0,
            'score' => $data['score'] ?? 0,
            'locked_at' => ($data['status'] ?? '') === 'locked' ? now() : null,
        ]);

        $this->audit->record('kpi_target', $target->id, 'create_kpi_target', $request->user(), null, $target->toArray(), $request);

        return response()->json(['data' => $target->load('definition:id,code,name')], 201);
    }

    public function updateTarget(Request $request, KpiTarget $kpiTarget): JsonResponse
    {
        abort_if($kpiTarget->tenant_id !== $request->user()->tenant_id, 404);
        abort_if($kpiTarget->locked_at, 422, 'Kỳ KPI đã khóa, không thể sửa mục tiêu.');

        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'kpi_definition_id' => ['required', Rule::exists('kpi_definitions', 'id')->where('tenant_id', $tenantId)],
            'period_type' => ['required', Rule::in(['day', 'week', 'month', 'quarter', 'year'])],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'target_value' => ['required', 'numeric', 'min:0'],
            'actual_value' => ['nullable', 'numeric', 'min:0'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['required', Rule::in(['draft', 'active'])],
        ]);

        $old = $kpiTarget->toArray();
        $kpiTarget->update($data + [
            'actual_value' => $data['actual_value'] ?? 0,
            'score' => $data['score'] ?? 0,
        ]);
        $this->audit->record('kpi_target', $kpiTarget->id, 'update_kpi_target', $request->user(), $old, $kpiTarget->fresh()->toArray(), $request);

        return response()->json(['data' => $kpiTarget->refresh()->load('definition:id,code,name')]);
    }

    public function lockTarget(Request $request, KpiTarget $kpiTarget): JsonResponse
    {
        abort_if($kpiTarget->tenant_id !== $request->user()->tenant_id, 404);

        $old = $kpiTarget->only(['status', 'locked_at']);
        $kpiTarget->update([
            'status' => 'locked',
            'locked_at' => $kpiTarget->locked_at ?: now(),
        ]);

        $this->audit->record('kpi_target', $kpiTarget->id, 'lock_kpi_target', $request->user(), $old, $kpiTarget->fresh()->only(['status', 'locked_at']), $request);

        return response()->json(['data' => $kpiTarget->refresh()->load('definition:id,code,name')]);
    }

    public function exceptions(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = KpiException::where('tenant_id', $request->user()->tenant_id)
            ->with(['definition:id,code,name', 'user:id,name,email']);
        $this->scopeEmployeePoints($query, $request->user());

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $exceptions = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $exceptions->items(),
            'meta' => [
                'page' => $exceptions->currentPage(),
                'page_size' => $exceptions->perPage(),
                'total' => $exceptions->total(),
            ],
        ]);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = KpiAdjustment::where('tenant_id', $tenantId)
            ->with([
                'user:id,name,email,department_id',
                'user.department:id,name',
                'definition:id,code,name',
                'creator:id,name,email',
                'reviewer:id,name,email',
            ])
            ->latest('period_start')
            ->latest('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($periodType = $request->query('period_type')) {
            $query->where('period_type', $periodType);
        }

        $this->scopeEmployeePoints($query, $request->user());
        $adjustments = $query->paginate($pageSize);

        return response()->json([
            'data' => $adjustments->items(),
            'meta' => [
                'page' => $adjustments->currentPage(),
                'page_size' => $adjustments->perPage(),
                'total' => $adjustments->total(),
            ],
        ]);
    }

    public function adjustmentSummary(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $periodType = $request->query('period_type', 'month');
        $periodStart = $request->query('period_start');

        $query = KpiAdjustment::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->where('period_type', $periodType);

        if ($periodStart) {
            $query->whereDate('period_start', $periodStart);
        }

        $this->scopeEmployeePoints($query, $request->user());
        $rows = $query
            ->select('user_id')
            ->selectRaw("SUM(CASE WHEN adjustment_type = 'bonus' THEN points ELSE -points END) as net_points")
            ->selectRaw("SUM(CASE WHEN adjustment_type = 'bonus' THEN points ELSE 0 END) as bonus_points")
            ->selectRaw("SUM(CASE WHEN adjustment_type = 'penalty' THEN points ELSE 0 END) as penalty_points")
            ->selectRaw('COUNT(*) as adjustment_count')
            ->groupBy('user_id')
            ->orderByDesc('net_points')
            ->get();

        $users = User::where('tenant_id', $tenantId)
            ->whereIn('id', $rows->pluck('user_id'))
            ->with('department:id,name')
            ->get(['id', 'name', 'email', 'department_id'])
            ->keyBy('id');

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'user' => $users->get($row->user_id),
                'net_points' => (float) $row->net_points,
                'bonus_points' => (float) $row->bonus_points,
                'penalty_points' => (float) $row->penalty_points,
                'adjustment_count' => (int) $row->adjustment_count,
            ])->values(),
        ]);
    }

    private function scopeEmployeePoints(\Illuminate\Database\Eloquent\Builder $query, User $actor): void
    {
        if ($actor->dataScope() === 'company') return;
        $query->where(function ($scope) use ($actor) {
            $scope->where('user_id', $actor->id);
            if ($actor->dataScope() === 'department' && $actor->department_id) {
                $scope->orWhereHas('user', fn ($user) => $user->where('tenant_id', $actor->tenant_id)->where('department_id', $actor->department_id));
            }
        });
    }

    public function storeAdjustment(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'kpi_definition_id' => ['nullable', Rule::exists('kpi_definitions', 'id')->where('tenant_id', $tenantId)],
            'adjustment_type' => ['required', Rule::in(['bonus', 'penalty'])],
            'points' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'period_type' => ['nullable', Rule::in(['day', 'week', 'month', 'quarter', 'year'])],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'source_type' => ['nullable', 'string', 'max:100'],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'reason_code' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence_url' => ['nullable', 'string', 'max:500'],
        ]);

        $adjustment = KpiAdjustment::create($data + [
            'tenant_id' => $tenantId,
            'created_by' => $request->user()->id,
            'period_type' => $data['period_type'] ?? 'month',
            'status' => 'pending',
        ]);

        $this->audit->record(
            'kpi_adjustment',
            $adjustment->id,
            'create_kpi_adjustment',
            $request->user(),
            null,
            $adjustment->only(['user_id', 'adjustment_type', 'points', 'period_type', 'period_start', 'period_end', 'status', 'reason']),
            $request,
        );

        return response()->json([
            'data' => $adjustment->load(['user:id,name,email', 'definition:id,code,name', 'creator:id,name,email']),
        ], 201);
    }

    public function reviewAdjustment(Request $request, KpiAdjustment $kpiAdjustment): JsonResponse
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request,$kpiAdjustment) {
        $kpiAdjustment=KpiAdjustment::whereKey($kpiAdjustment->id)->lockForUpdate()->firstOrFail();
        abort_if($kpiAdjustment->tenant_id !== $request->user()->tenant_id, 404);
        abort_unless(\App\Support\DataScope::owned(KpiAdjustment::whereKey($kpiAdjustment->id),$request->user(),'user_id','user')->exists(),404);
        abort_if((int)$kpiAdjustment->created_by === (int)$request->user()->id,403,'Người lập không được tự duyệt phiếu điểm.');
        abort_if($kpiAdjustment->status !== 'pending',409,'Phiếu điểm đã được xử lý.');

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $old = $kpiAdjustment->only(['status', 'reviewed_by', 'reviewed_at', 'review_note']);
        $kpiAdjustment->update([
            'status' => $data['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ]);

        $this->audit->record(
            'kpi_adjustment',
            $kpiAdjustment->id,
            'review_kpi_adjustment',
            $request->user(),
            $old,
            $kpiAdjustment->fresh()->only(['status', 'reviewed_by', 'reviewed_at', 'review_note']),
            $request,
            $data['review_note'] ?? null,
        );

        return response()->json([
            'data' => $kpiAdjustment->refresh()->load(['user:id,name,email', 'definition:id,code,name', 'reviewer:id,name,email']),
        ]);
        });
    }

    public function storeException(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'kpi_definition_id' => ['nullable', Rule::exists('kpi_definitions', 'id')->where('tenant_id', $tenantId)],
            'kpi_target_id' => ['nullable', Rule::exists('kpi_targets', 'id')->where('tenant_id', $tenantId)],
            'kpi_score_snapshot_id' => ['nullable', Rule::exists('kpi_score_snapshots', 'id')->where('tenant_id', $tenantId)],
            'period_type' => ['nullable', Rule::in(['day', 'week', 'month', 'quarter', 'year'])],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence_url' => ['nullable', 'string', 'max:500'],
        ]);

        $exception = KpiException::create($data + [
            'tenant_id' => $tenantId,
            'user_id' => $request->user()->id,
            'period_type' => $data['period_type'] ?? 'month',
            'status' => 'pending',
        ]);
        $this->audit->record(
            'kpi_exception',
            $exception->id,
            'create_kpi_exception',
            $request->user(),
            null,
            $exception->only(['period_type', 'period_start', 'period_end', 'status', 'reason']),
            $request,
        );

        return response()->json(['data' => $exception->load(['definition:id,code,name', 'user:id,name,email'])], 201);
    }

    public function reviewException(Request $request, KpiException $kpiException): JsonResponse
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request,$kpiException) {
        $kpiException=KpiException::whereKey($kpiException->id)->lockForUpdate()->firstOrFail();
        abort_if($kpiException->tenant_id !== $request->user()->tenant_id, 404);
        abort_unless(\App\Support\DataScope::owned(KpiException::whereKey($kpiException->id),$request->user(),'user_id','user')->exists(),404);
        abort_if((int)$kpiException->user_id === (int)$request->user()->id,403,'Người lập không được tự duyệt ngoại lệ của mình.');
        abort_if($kpiException->status !== 'pending',409,'Ngoại lệ đã được xử lý.');

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $old = $kpiException->only(['status', 'reviewed_by', 'reviewed_at', 'review_note']);
        $kpiException->update([
            'status' => $data['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ]);
        $this->audit->record(
            'kpi_exception',
            $kpiException->id,
            'review_kpi_exception',
            $request->user(),
            $old,
            $kpiException->fresh()->only(['status', 'reviewed_by', 'reviewed_at', 'review_note']),
            $request,
            $data['review_note'] ?? null,
        );

        return response()->json(['data' => $kpiException->refresh()->load(['definition:id,code,name', 'user:id,name,email'])]);
        });
    }
}
