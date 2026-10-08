<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserRoleAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_user_and_assign_roles(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $department = Department::where('code', 'SALES')->firstOrFail();
        $position = Position::where('code', 'STAFF')->firstOrFail();
        $role = Role::where('code', 'ROLE-SALES')->firstOrFail();

        $create = $this->withToken($token)
            ->postJson('/api/v1/users', [
                'name' => 'Nhân viên demo mới',
                'email' => 'new-user@vk-kpi.local',
                'password' => 'Admin@123',
                'department_id' => $department->id,
                'position_id' => $position->id,
                'role_ids' => [$role->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'new-user@vk-kpi.local');

        $userId = $create->json('data.id');
        $this->assertDatabaseHas('user_roles', ['user_id' => $userId, 'role_id' => $role->id]);

        $adminRole = Role::where('code', 'ROLE-ADMIN')->firstOrFail();
        $this->withToken($token)
            ->postJson("/api/v1/users/{$userId}/roles", ['role_ids' => [$adminRole->id]])
            ->assertOk()
            ->assertJsonPath('data.roles.0.code', 'ROLE-ADMIN');

        $this->withToken($token)
            ->postJson("/api/v1/users/{$userId}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_can_create_role_and_sync_permissions(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $permission = Permission::where('code', 'master.view')->firstOrFail();
        $auditPermission = Permission::where('code', 'audit.view')->firstOrFail();

        $create = $this->withToken($token)
            ->postJson('/api/v1/roles', [
                'name' => 'Vai trò demo',
                'data_scope' => 'department',
                'permission_ids' => [$permission->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Vai trò demo');

        $roleId = $create->json('data.id');
        $this->assertDatabaseHas('role_permissions', ['role_id' => $roleId, 'permission_id' => $permission->id]);

        $this->withToken($token)
            ->postJson("/api/v1/roles/{$roleId}/permissions", ['permission_ids' => [$auditPermission->id]])
            ->assertOk()
            ->assertJsonPath('data.permissions.0.code', 'audit.view');
    }

    public function test_admin_can_manage_departments_positions_and_assign_user_organization(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $user = User::where('email', 'sales@vk-kpi.local')->firstOrFail();

        $departmentId = $this->withToken($token)
            ->postJson('/api/v1/departments', [
                'name' => 'Chăm sóc khách hàng',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Chăm sóc khách hàng')
            ->json('data.id');

        $positionId = $this->withToken($token)
            ->postJson('/api/v1/positions', [
                'name' => 'Trưởng nhóm chăm sóc',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Trưởng nhóm chăm sóc')
            ->json('data.id');

        $this->withToken($token)
            ->putJson("/api/v1/departments/{$departmentId}", [
                'name' => 'Chăm sóc khách hàng doanh nghiệp',
                'parent_id' => null,
                'manager_id' => $user->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Chăm sóc khách hàng doanh nghiệp');

        $this->withToken($token)
            ->putJson("/api/v1/positions/{$positionId}", [
                'name' => 'Trưởng nhóm CS',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Trưởng nhóm CS');

        $organization = $this->withToken($token)
            ->postJson("/api/v1/users/{$user->id}/organization", [
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'manager_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.department.name', 'Chăm sóc khách hàng doanh nghiệp')
            ->assertJsonPath('data.position.name', 'Trưởng nhóm CS');

        $this->assertMatchesRegularExpression('/^DEP-\d{5}$/', $organization->json('data.department.code'));
        $this->assertMatchesRegularExpression('/^POS-\d{5}$/', $organization->json('data.position.code'));
    }

    public function test_sales_user_cannot_manage_users_or_roles(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');

        $this->withToken($token)->getJson('/api/v1/users')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/roles')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/departments')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/positions')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/sla-policies')->assertForbidden();
    }

    public function test_admin_can_manage_sla_policies(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');

        $totalBeforeCreate = $this->withToken($token)
            ->getJson('/api/v1/sla-policies?page_size=100')
            ->assertOk()
            ->json('meta.total');

        $policyId = $this->withToken($token)
            ->postJson('/api/v1/sla-policies', [
                'module' => 'finance',
                'task_type' => 'payment_follow_up',
                'priority' => 'high',
                'duration_minutes' => 120,
                'warning_before_minutes' => 30,
                'escalation_rules' => ['manager' => true],
            ])
            ->assertCreated()
            ->assertJsonPath('data.module', 'finance')
            ->assertJsonPath('data.task_type', 'payment_follow_up')
            ->json('data.id');

        $this->withToken($token)
            ->putJson("/api/v1/sla-policies/{$policyId}", [
                'module' => 'finance',
                'task_type' => 'payment_follow_up',
                'priority' => 'high',
                'duration_minutes' => 180,
                'warning_before_minutes' => 45,
                'escalation_rules' => ['manager' => false],
            ])
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 180)
            ->assertJsonPath('data.warning_before_minutes', 45);

        $this->assertDatabaseHas('sla_policies', [
            'id' => $policyId,
            'duration_minutes' => 180,
            'warning_before_minutes' => 45,
        ]);

        $this->assertSame(false, SlaPolicy::findOrFail($policyId)->escalation_rules['manager']);
        $this->assertSame($totalBeforeCreate + 1, SlaPolicy::where('tenant_id', Tenant::firstOrFail()->id)->count());
    }

    public function test_admin_can_upload_tenant_logo(): void
    {
        Storage::fake('public');
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');

        $response = $this->withToken($token)
            ->post('/api/v1/tenant/logo', [
                'logo' => UploadedFile::fake()->image('logo.png', 240, 120),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.logo_url', '/storage/'.$response->json('data.logo_path'));

        $tenant = Tenant::where('code', 'VK-KPI')->firstOrFail();
        Storage::disk('public')->assertExists($tenant->logo_path);

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.logo_url', '/storage/'.$tenant->logo_path);
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
