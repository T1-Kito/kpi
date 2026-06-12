<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KpiException;
use App\Models\KpiScoreSnapshot;
use App\Models\KpiTarget;
use App\Services\Kpi\KpiService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KpiController extends Controller
{
    public function __construct(
        private readonly KpiService $kpis,
        private readonly AuditLogger $audit,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->kpis->overview($request->user()->tenant_id)]);
    }

    public function snapshots(Request $request): JsonResponse
    {
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

    public function exceptions(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = KpiException::where('tenant_id', $request->user()->tenant_id)
            ->with(['definition:id,code,name', 'user:id,name,email']);

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
        abort_if($kpiException->tenant_id !== $request->user()->tenant_id, 404);

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
    }
}
