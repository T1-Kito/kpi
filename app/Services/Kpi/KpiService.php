<?php

namespace App\Services\Kpi;

use App\Models\Alert;
use App\Models\GoodsIssue;
use App\Models\GoodsReceipt;
use App\Models\InventoryBalance;
use App\Models\KpiException;
use App\Models\KpiDefinition;
use App\Models\KpiScoreSnapshot;
use App\Models\KpiTarget;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class KpiService
{
    /** @return array<string, mixed> */
    public function buildMetrics(int $tenantId): array
    {
        $leads = Lead::where('tenant_id', $tenantId)->get();
        $quotations = Quotation::where('tenant_id', $tenantId)->with('items')->get();
        $salesOrders = SalesOrder::where('tenant_id', $tenantId)->with('items')->get();
        $purchaseRequests = DB::table('purchase_requests')->where('tenant_id', $tenantId)->get();
        $purchaseOrders = DB::table('purchase_orders')->where('tenant_id', $tenantId)->get();
        $balances = InventoryBalance::where('tenant_id', $tenantId)->with('sku')->get();
        $receipts = GoodsReceipt::where('tenant_id', $tenantId)->get();
        $issues = GoodsIssue::where('tenant_id', $tenantId)->get();
        $tasks = Task::where('tenant_id', $tenantId)->get();
        $alerts = Alert::where('tenant_id', $tenantId)->get();

        $revenue = $salesOrders->sum('total_amount');
        $quotationValue = $quotations->sum('total_amount');
        $purchaseValue = $purchaseOrders->sum('total_amount');
        $available = $balances->sum('available');
        $reserved = $balances->sum('reserved');
        $orderTotal = $salesOrders->count();
        $quotationTotal = $quotations->count();
        $leadTotal = $leads->count();
        $poTotal = $purchaseOrders->count();
        $taskTotal = $tasks->count();
        $completedTasks = $tasks->where('status', 'completed')->count();
        $overdueTasks = $tasks->where('status', 'overdue')->count();
        $resolvedAlerts = $alerts->where('status', 'resolved')->count();
        $openAlerts = $alerts->where('status', 'open')->count();
        $approvedPrs = $purchaseRequests->where('status', 'approved')->count();
        $approvedPos = $purchaseOrders->whereIn('status', ['approved', 'received', 'partially_received'])->count();
        $receivedPos = $purchaseOrders->whereIn('status', ['received', 'partially_received'])->count();
        $pendingQuotes = $quotations->where('status', 'pending_approval')->count();
        $lowStockRows = $balances->filter(fn ($row) => (float) $row->available <= (float) ($row->sku->min_stock ?? 0))->count();
        $shortageOrders = $salesOrders->where('stock_status', 'shortage')->count();

        $salesScore = $this->score([
            $this->ratio($orderTotal, max($quotationTotal, 1)) * 45,
            $this->ratio($quotationTotal, max($leadTotal, $quotationTotal, 1)) * 25,
            $this->ratio($revenue, max($quotationValue, 1)) * 20,
            $shortageOrders === 0 ? 10 : max(0, 10 - $shortageOrders * 3),
        ]);

        $inventoryScore = $this->score([
            $this->ratio($available, max($available + $reserved, 1)) * 45,
            $lowStockRows === 0 ? 30 : max(0, 30 - $lowStockRows * 8),
            $this->ratio($receipts->count() + $issues->count(), max($poTotal + $orderTotal, 1)) * 25,
        ]);

        $procurementScore = $this->score([
            $this->ratio($approvedPrs, max($purchaseRequests->count(), 1)) * 35,
            $this->ratio($approvedPos, max($poTotal, 1)) * 35,
            $this->ratio($receivedPos, max($poTotal, 1)) * 20,
            $purchaseValue > 0 ? 10 : 0,
        ]);

        $slaRate = (int) round($this->ratio($completedTasks, max($taskTotal, 1)) * 100);
        $workflowScore = $this->score([
            $this->ratio($completedTasks, max($taskTotal, 1)) * 45,
            $overdueTasks === 0 ? 25 : max(0, 25 - $overdueTasks * 6),
            $this->ratio($resolvedAlerts, max($alerts->count(), 1)) * 20,
            $openAlerts === 0 ? 10 : max(0, 10 - $openAlerts * 3),
        ]);

        $poApprovalRate = (int) round($this->ratio($approvedPos, max($poTotal, 1)) * 100);
        $overall = $this->average([$salesScore, $inventoryScore, $procurementScore, $workflowScore]);

        return [
            'revenue' => $revenue,
            'purchaseValue' => $purchaseValue,
            'available' => $available,
            'salesScore' => $salesScore,
            'inventoryScore' => $inventoryScore,
            'procurementScore' => $procurementScore,
            'workflowScore' => $workflowScore,
            'totalScore' => $overall,
            'completedTasks' => $completedTasks,
            'overdueTasks' => $overdueTasks,
            'openAlerts' => $openAlerts,
            'pendingQuotes' => $pendingQuotes,
            'lowStockRows' => $lowStockRows,
            'approvedPos' => $approvedPos,
            'slaRate' => $slaRate,
            'poApprovalRate' => $poApprovalRate,
            'counts' => [
                'leads' => $leadTotal,
                'quotations' => $quotationTotal,
                'salesOrders' => $orderTotal,
                'purchaseRequests' => $purchaseRequests->count(),
                'purchaseOrders' => $poTotal,
                'receipts' => $receipts->count(),
                'issues' => $issues->count(),
                'tasks' => $taskTotal,
                'alerts' => $alerts->count(),
            ],
        ];
    }

    public function calculateSnapshot(Tenant $tenant, string $periodType = 'day', ?Carbon $date = null): KpiScoreSnapshot
    {
        $date ??= now()->toDateString();
        $metrics = $this->buildMetrics($tenant->id);
        $period = $this->periodBounds($periodType, Carbon::parse($date));
        $snapshotDate = $period['end']->toDateString();

        return DB::transaction(function () use ($tenant, $periodType, $snapshotDate, $metrics, $period) {
            $existing = KpiScoreSnapshot::where('tenant_id', $tenant->id)
                ->whereDate('snapshot_date', $snapshotDate)
                ->where('period_type', $periodType)
                ->first();
            abort_if($existing?->locked_at || $existing?->status === 'locked', 422, 'Kỳ KPI đã khóa, không thể tính lại.');

            $snapshot = $existing ?? new KpiScoreSnapshot([
                'tenant_id' => $tenant->id,
                'snapshot_date' => $snapshotDate,
                'period_type' => $periodType,
            ]);
            $snapshot->fill([
                'overall_score' => $metrics['totalScore'],
                'metrics' => $metrics,
                'status' => 'draft',
                'locked_at' => null,
            ])->save();

            $definitions = KpiDefinition::where('tenant_id', $tenant->id)->where('status', 'active')->get();
            foreach ($definitions as $definition) {
                $target = KpiTarget::where('tenant_id', $tenant->id)
                    ->where('kpi_definition_id', $definition->id)
                    ->where('period_type', $periodType)
                    ->whereDate('period_start', $period['start']->toDateString())
                    ->first() ?? new KpiTarget([
                        'tenant_id' => $tenant->id,
                        'kpi_definition_id' => $definition->id,
                        'period_type' => $periodType,
                        'period_start' => $period['start']->toDateString(),
                    ]);
                if ($target->exists && $target->locked_at) {
                    continue;
                }
                $target->period_end = $period['end']->toDateString();
                $target->target_value = $target->target_value ?: $this->defaultTargetValue($definition->code);
                $target->actual_value = $this->actualValueFor($definition->code, $metrics);
                $target->score = $this->scoreForTarget($definition->target_direction, (float) $target->actual_value, (float) $target->target_value);
                $target->status = $target->status === 'locked' ? 'locked' : 'draft';
                $target->save();
            }

            return $snapshot->refresh();
        });
    }

    public function overview(int $tenantId): array
    {
        $metrics = $this->buildMetrics($tenantId);
        $snapshot = KpiScoreSnapshot::where('tenant_id', $tenantId)->latest('snapshot_date')->first();
        $targets = KpiTarget::where('tenant_id', $tenantId)->with('definition:id,code,name')->latest('id')->limit(6)->get();
        $exceptions = KpiException::where('tenant_id', $tenantId)
            ->with(['definition:id,code,name', 'user:id,name,email'])
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        return [
            'metrics' => $metrics,
            'snapshot' => $snapshot,
            'targets' => $targets,
            'exceptions' => $exceptions,
        ];
    }

    private function actualValueFor(string $code, array $metrics): float
    {
        return match ($code) {
            'KPI-SALES' => (float) $metrics['salesScore'],
            'KPI-INVENTORY' => (float) $metrics['inventoryScore'],
            'KPI-PROCUREMENT' => (float) $metrics['procurementScore'],
            'KPI-WORKFLOW' => (float) $metrics['workflowScore'],
            'KPI-OVERALL' => (float) $metrics['totalScore'],
            default => 0,
        };
    }

    private function defaultTargetValue(string $code): float
    {
        return match ($code) {
            'KPI-SALES' => 75,
            'KPI-INVENTORY' => 72,
            'KPI-PROCUREMENT' => 70,
            'KPI-WORKFLOW' => 65,
            'KPI-OVERALL' => 75,
            default => 0,
        };
    }

    private function scoreForTarget(string $direction, float $actual, float $target): float
    {
        if ($target <= 0) {
            return 0;
        }

        $ratio = $direction === 'decrease'
            ? max(0, min(1, 1 - (($actual - $target) / max($target, 1))))
            : max(0, min(1, $actual / $target));

        return round($ratio * 100, 2);
    }

    private function score(array $parts): int
    {
        return (int) $this->clamp(round(array_sum($parts)), 0, 100);
    }

    private function ratio(float|int $value, float|int $base): float
    {
        return max(0, min(1, (float) $value / max((float) $base, 1)));
    }

    private function average(array $values): int
    {
        $valid = array_filter($values, fn ($value) => is_numeric($value));
        return $valid ? (int) round(array_sum($valid) / count($valid)) : 0;
    }

    private function clamp(float|int $value, float|int $min, float|int $max): float|int
    {
        return max($min, min($max, $value));
    }

    /** @return array{start: Carbon, end: Carbon} */
    private function periodBounds(string $periodType, Carbon $date): array
    {
        return match ($periodType) {
            'day' => ['start' => $date->copy()->startOfDay(), 'end' => $date->copy()->endOfDay()],
            'week' => ['start' => $date->copy()->startOfWeek(), 'end' => $date->copy()->endOfWeek()],
            'quarter' => ['start' => $date->copy()->startOfQuarter(), 'end' => $date->copy()->endOfQuarter()],
            'year' => ['start' => $date->copy()->startOfYear(), 'end' => $date->copy()->endOfYear()],
            default => ['start' => $date->copy()->startOfMonth(), 'end' => $date->copy()->endOfMonth()],
        };
    }
}
