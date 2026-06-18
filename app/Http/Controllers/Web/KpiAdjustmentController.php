<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\KpiAdjustment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KpiAdjustmentController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tenant = Tenant::query()->where('status', 'active')->firstOrFail();
        $periodType = $request->query('period_type', 'month');
        $status = $request->query('status', '');

        $query = KpiAdjustment::query()
            ->where('tenant_id', $tenant->id)
            ->with([
                'user:id,name,email,department_id',
                'user.department:id,name',
                'definition:id,code,name',
                'creator:id,name,email',
                'reviewer:id,name,email',
            ])
            ->latest('period_start')
            ->latest('id');

        if ($periodType) {
            $query->where('period_type', $periodType);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $adjustments = $query->get();

        $summaryRows = KpiAdjustment::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'approved')
            ->when($periodType, fn ($q) => $q->where('period_type', $periodType))
            ->select('user_id')
            ->selectRaw("SUM(CASE WHEN adjustment_type = 'bonus' THEN points ELSE -points END) as net_points")
            ->selectRaw("SUM(CASE WHEN adjustment_type = 'bonus' THEN points ELSE 0 END) as bonus_points")
            ->selectRaw("SUM(CASE WHEN adjustment_type = 'penalty' THEN points ELSE 0 END) as penalty_points")
            ->selectRaw('COUNT(*) as adjustment_count')
            ->groupBy('user_id')
            ->orderByDesc('net_points')
            ->get();

        $users = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $summaryRows->pluck('user_id'))
            ->with('department:id,name')
            ->get(['id', 'name', 'email', 'department_id'])
            ->keyBy('id');

        $summary = $summaryRows->map(fn ($row) => [
            'user' => $users->get($row->user_id),
            'net_points' => (float) $row->net_points,
            'bonus_points' => (float) $row->bonus_points,
            'penalty_points' => (float) $row->penalty_points,
            'adjustment_count' => (int) $row->adjustment_count,
        ]);

        return view('pages.kpi-adjustments.index', [
            'adjustments' => $adjustments,
            'summary' => $summary,
            'periodType' => $periodType,
            'status' => $status,
        ]);
    }
}
