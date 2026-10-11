<?php

namespace Tests\Feature;

use App\Models\KpiAssignment;
use App\Models\KpiBundle;
use App\Models\KpiDefinition;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiBundleTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Admin@123'])->assertOk()->json('data.access_token');
    }

    private function payload(User $admin, float $secondWeight = 40): array
    {
        $definitions = KpiDefinition::where('tenant_id', $admin->tenant_id)->where('status', 'active')->take(2)->get();
        return ['code' => 'sales-standard', 'name' => 'Nhân viên kinh doanh chuẩn', 'description' => 'Bộ KPI kiểm thử', 'items' => [
            ['kpi_definition_id' => $definitions[0]->id, 'metric' => 'tasks_completed', 'target_value' => 10, 'weight' => 60, 'direction' => 'increase'],
            ['kpi_definition_id' => $definitions[1]->id, 'metric' => 'leads_assigned', 'target_value' => 5, 'weight' => $secondWeight, 'direction' => 'increase'],
        ]];
    }

    public function test_bundle_requires_one_hundred_percent_is_versioned_and_applies_atomically(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $token = $this->token($admin);
        $this->withToken($token)->postJson('/api/v1/kpi/bundles', $this->payload($admin, 30))
            ->assertUnprocessable()->assertJsonStructure(['fields' => ['items']]);
        $id = $this->withToken($token)->postJson('/api/v1/kpi/bundles', $this->payload($admin))->assertCreated()
            ->assertJsonPath('data.code', 'SALES-STANDARD')->assertJsonPath('data.version', 1)->assertJsonCount(2, 'data.items')->json('data.id');
        $this->withToken($token)->postJson("/api/v1/kpi/bundles/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'active');
        $this->withToken($token)->putJson("/api/v1/kpi/bundles/{$id}", $this->payload($admin))->assertUnprocessable();
        $copy = $this->withToken($token)->postJson("/api/v1/kpi/bundles/{$id}/duplicate")->assertCreated()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'draft')->json('data.id');
        $apply = ['audience_type' => 'department', 'audience_id' => $sales->department_id, 'period_type' => 'month', 'date' => '2026-10-11', 'status' => 'active', 'recurring' => true];
        $this->withToken($token)->postJson("/api/v1/kpi/bundles/{$id}/apply", $apply)->assertCreated()
            ->assertJsonPath('meta.employees', 1)->assertJsonPath('meta.assignments', 2)->assertJsonCount(2, 'data');
        $this->assertSame(2, KpiAssignment::where('kpi_bundle_id', $id)->count());
        $this->assertDatabaseHas('kpi_bundle_plans', ['kpi_bundle_id' => $id, 'audience_type' => 'department', 'audience_id' => $sales->department_id, 'status' => 'active']);
        $this->withToken($token)->postJson("/api/v1/kpi/bundles/{$id}/apply", $apply)->assertUnprocessable();
        $this->assertSame(2, KpiAssignment::count());
        $this->withToken($this->token($sales))->getJson('/api/v1/kpi/mine?date=2026-10-11')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->putJson("/api/v1/kpi/bundles/{$copy}", array_replace($this->payload($admin), ['name' => 'Phiên bản hai']))->assertOk();
        $this->withToken($token)->postJson("/api/v1/kpi/bundles/{$copy}/publish")->assertOk();
        $this->assertSame('inactive', KpiBundle::findOrFail($id)->status);
        $this->assertSame('active', KpiBundle::findOrFail($copy)->status);
        $this->assertDatabaseHas('kpi_bundle_plans', ['kpi_bundle_id' => $id, 'status' => 'inactive']);
        $this->artisan('kpi:rollout-bundles')->assertSuccessful();
        $this->assertSame(2, KpiAssignment::count(), 'Inactive previous bundle plan must not create assignments.');
    }

    public function test_bundle_permissions_tenant_boundaries_empty_audience_and_inactive_version_are_enforced(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $token = $this->token($admin);
        $id = $this->withToken($token)->postJson('/api/v1/kpi/bundles', $this->payload($admin))->assertCreated()->json('data.id');
        $this->withToken($this->token($sales))->getJson('/api/v1/kpi/bundles')->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/kpi/bundles/{$id}/apply", ['audience_type' => 'department', 'audience_id' => $sales->department_id, 'period_type' => 'month', 'date' => '2026-10-11', 'status' => 'active'])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/kpi/bundles/{$id}/publish")->assertOk();
        User::where('department_id', $sales->department_id)->update(['is_active' => false]);
        $this->withToken($token)->postJson("/api/v1/kpi/bundles/{$id}/apply", ['audience_type' => 'department', 'audience_id' => $sales->department_id, 'period_type' => 'month', 'date' => '2026-10-11', 'status' => 'active'])
            ->assertUnprocessable()->assertJsonStructure(['fields' => ['audience_id']]);
        $other = Tenant::create(['code' => 'OTHER-BUNDLE', 'name' => 'Other', 'status' => 'active']);
        $foreign = KpiBundle::create(['tenant_id' => $other->id, 'code' => 'FOREIGN', 'name' => 'Foreign', 'version' => 1, 'status' => 'active', 'created_by' => $admin->id]);
        $this->withToken($token)->postJson("/api/v1/kpi/bundles/{$foreign->id}/duplicate")->assertNotFound();
    }
}
