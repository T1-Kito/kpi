<?php

namespace Tests\Feature;

use App\Models\KpiAssignment;
use App\Models\KpiBundle;
use App\Models\KpiBundlePlan;
use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardKpiSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_idempotently_setup_standard_department_kpis(): void
    {
        $this->seed();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@vk-kpi.local', 'password' => 'Admin@123'])
            ->json('data.access_token');

        $first = $this->withToken($token)->postJson('/api/v1/kpi/standard-setup')->assertOk();
        $this->assertGreaterThanOrEqual(5, count($first->json('data.configured')));
        $this->assertGreaterThan(0, $first->json('data.created_assignments'));
        $this->assertDatabaseHas('kpi_definitions', ['code' => 'STD-PAYMENTS', 'formula' => 'payments_received_amount', 'status' => 'active']);
        $this->assertDatabaseHas('kpi_bundles', ['code' => 'STD-SALES', 'status' => 'active']);
        $this->assertDatabaseHas('kpi_bundle_plans', ['period_type' => 'month', 'status' => 'active']);

        $counts = [KpiDefinition::count(), KpiBundle::count(), KpiBundlePlan::count(), KpiAssignment::count()];
        $this->withToken($token)->postJson('/api/v1/kpi/standard-setup')->assertOk()
            ->assertJsonPath('data.created_assignments', 0);
        $this->assertSame($counts, [KpiDefinition::count(), KpiBundle::count(), KpiBundlePlan::count(), KpiAssignment::count()]);

        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $this->withToken($this->postJson('/api/v1/auth/login', ['email' => $sales->email, 'password' => 'Admin@123'])->json('data.access_token'))
            ->getJson('/api/v1/kpi/mine')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_standard_setup_requires_kpi_configuration_permission(): void
    {
        $this->seed();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'sales@vk-kpi.local', 'password' => 'Admin@123'])
            ->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/kpi/standard-setup')->assertForbidden();
    }
}
