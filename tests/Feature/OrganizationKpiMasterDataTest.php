<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\KpiAssignment;
use App\Models\KpiDefinition;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationKpiMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Admin@123'])->assertOk()->json('data.access_token');
    }

    public function test_preview_uses_live_active_membership_and_excludes_other_tenants(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $department = Department::create(['tenant_id' => $admin->tenant_id, 'code' => 'DEP-NEW', 'name' => 'New group']);
        $other = Tenant::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $foreign = Department::create(['tenant_id' => $other->id, 'code' => 'DEP-OTHER', 'name' => 'Foreign']);
        $token = $this->login($admin);
        $payload = ['audience_type' => 'department', 'audience_id' => $department->id];
        $this->withToken($token)->postJson('/api/v1/kpi/assignments/preview', $payload)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $sales->update(['department_id' => $department->id, 'position_id' => null]);
        $this->withToken($token)->postJson('/api/v1/kpi/assignments/preview', $payload)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sales->id)->assertJsonPath('meta.missing_position', 1);
        $this->withToken($token)->postJson('/api/v1/kpi/assignments/preview', ['audience_type' => 'department', 'audience_id' => $foreign->id])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/kpi/assignments/preview', ['audience_type' => 'arbitrary'])->assertUnprocessable();
        $this->assertSame(0, KpiAssignment::count());
        $salesToken = $this->login($sales);
        $this->withToken($salesToken)->postJson('/api/v1/kpi/assignments/preview', $payload)->assertForbidden();
        $sales->update(['is_active' => false]);
        $this->withToken($token)->postJson('/api/v1/kpi/assignments/preview', $payload)->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->postJson('/api/v1/kpi/assignments', [
            'kpi_definition_id' => KpiDefinition::where('tenant_id', $admin->tenant_id)->firstOrFail()->id,
            'audience_type' => 'user', 'audience_id' => $sales->id, 'period_type' => 'month', 'date' => '2026-10-01',
            'metric' => 'tasks_completed', 'target_value' => 1, 'weight' => 1, 'direction' => 'increase', 'status' => 'active',
        ])->assertUnprocessable()->assertJsonStructure(['fields' => ['audience_id']]);
    }

    public function test_organization_rejects_direct_and_indirect_cycles_with_string_ids(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $token = $this->login($admin);
        $parent = Department::create(['tenant_id' => $admin->tenant_id, 'code' => 'DEP-PARENT', 'name' => 'Parent']);
        $child = Department::create(['tenant_id' => $admin->tenant_id, 'code' => 'DEP-CHILD', 'name' => 'Child', 'parent_id' => $parent->id]);
        foreach ([$parent->id, $child->id] as $id) {
            $this->withToken($token)->putJson('/api/v1/departments/'.$parent->id, ['name' => 'Parent', 'parent_id' => (string) $id])->assertUnprocessable()->assertJsonStructure(['fields' => ['parent_id']]);
        }
        $this->assertNull($parent->fresh()->parent_id);
        $this->withToken($token)->postJson('/api/v1/users/'.$sales->id.'/organization', ['manager_id' => (string) $admin->id])->assertOk();
        foreach ([$admin->id, $sales->id] as $id) {
            $this->withToken($token)->postJson('/api/v1/users/'.$admin->id.'/organization', ['manager_id' => (string) $id])->assertUnprocessable()->assertJsonStructure(['fields' => ['manager_id']]);
        }
        $this->withToken($token)->postJson('/api/v1/users/'.$sales->id.'/organization', ['manager_id' => null])->assertOk();
        $this->assertNull($sales->fresh()->manager_id);
    }
}
