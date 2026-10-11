<?php

namespace App\Services\Kpi;

use App\Models\KpiAssignment;
use App\Models\KpiBundlePlan;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KpiBundleRolloutService
{
    public function ensure(KpiBundlePlan $plan, CarbonImmutable $date): int
    {
        return DB::transaction(function () use ($plan, $date) {
            Tenant::whereKey($plan->tenant_id)->lockForUpdate()->firstOrFail();
            $plan = KpiBundlePlan::whereKey($plan->id)->lockForUpdate()->with('bundle.items')->firstOrFail();
            if ($plan->status !== 'active' || $plan->bundle?->status !== 'active' || $date->lt($plan->starts_on)) return 0;
            [$start, $end] = match ($plan->period_type) {
                'quarter' => [$date->startOfQuarter(), $date->endOfQuarter()],
                'year' => [$date->startOfYear(), $date->endOfYear()],
                default => [$date->startOfMonth(), $date->endOfMonth()],
            };
            $created = 0;
            foreach ($plan->bundle->items as $item) {
                $existing = KpiAssignment::where('tenant_id', $plan->tenant_id)->where('kpi_definition_id', $item->kpi_definition_id)
                    ->where('audience_type', $plan->audience_type)->where('audience_id', $plan->audience_id)
                    ->where('period_type', $plan->period_type)->whereDate('period_start', $start)->first();
                if ($existing) {
                    if ((int) $existing->kpi_bundle_id !== (int) $plan->kpi_bundle_id) throw new RuntimeException("KPI assignment conflict for plan {$plan->id}.");
                    continue;
                }
                KpiAssignment::create(['tenant_id' => $plan->tenant_id, 'kpi_definition_id' => $item->kpi_definition_id, 'kpi_bundle_id' => $plan->kpi_bundle_id,
                    'audience_type' => $plan->audience_type, 'audience_id' => $plan->audience_id, 'period_type' => $plan->period_type,
                    'period_start' => $start, 'period_end' => $end, 'metric' => $item->metric, 'target_value' => $item->target_value,
                    'weight' => $item->weight, 'direction' => $item->direction, 'status' => $plan->assignment_status, 'created_by' => $plan->created_by]);
                $created++;
            }
            return $created;
        });
    }
}
