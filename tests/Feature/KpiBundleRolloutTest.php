<?php

namespace Tests\Feature;

use App\Models\KpiAssignment;
use App\Models\KpiBundle;
use App\Models\KpiBundlePlan;
use App\Models\KpiDefinition;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiBundleRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_rollout_creates_current_period_once_and_keeps_template_values(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $definition = KpiDefinition::where('tenant_id', $admin->tenant_id)->where('status', 'active')->firstOrFail();
        $bundle = KpiBundle::create(['tenant_id' => $admin->tenant_id, 'code' => 'OPS', 'name' => 'Operations', 'version' => 1, 'status' => 'active', 'published_at' => now(), 'created_by' => $admin->id]);
        $bundle->items()->create(['kpi_definition_id' => $definition->id, 'metric' => 'tasks_completed', 'target_value' => 12, 'weight' => 100, 'direction' => 'increase', 'sort_order' => 1]);
        KpiBundlePlan::create(['tenant_id' => $admin->tenant_id, 'kpi_bundle_id' => $bundle->id, 'audience_type' => 'company', 'audience_id' => 0,
            'period_type' => 'month', 'assignment_status' => 'active', 'starts_on' => '2026-10-01', 'status' => 'active', 'created_by' => $admin->id]);
        $this->travelTo(Carbon::parse('2026-11-02 01:00:00'));
        $this->artisan('kpi:rollout-bundles')->assertSuccessful()->expectsOutput('Created 1 KPI assignment(s); 0 plan(s) need review.');
        $assignment = KpiAssignment::firstOrFail();
        $this->assertSame('2026-11-01', $assignment->period_start->toDateString());
        $this->assertSame('2026-11-30', $assignment->period_end->toDateString());
        $this->assertSame('12.00', $assignment->target_value);
        $this->artisan('kpi:rollout-bundles')->assertSuccessful()->expectsOutput('Created 0 KPI assignment(s); 0 plan(s) need review.');
        $this->assertSame(1, KpiAssignment::count());
    }
}
