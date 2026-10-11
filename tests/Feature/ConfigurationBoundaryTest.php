<?php

namespace Tests\Feature;

use App\Models\KpiAdjustment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Admin@123'])->assertOk()->json('data.access_token');
    }

    public function test_user_manager_cannot_assign_admin_roles_through_either_endpoint(): void
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $role = Role::create(['tenant_id' => $actor->tenant_id, 'code' => 'USER-ONLY', 'name' => 'User manager only', 'data_scope' => 'own']);
        $role->permissions()->sync([Permission::where('code', 'user.manage')->value('id')]);
        $actor->roles()->sync([$role->id]);
        $token = $this->token($actor);
        $adminRole = Role::where('code', 'ROLE-ADMIN')->firstOrFail();
        $this->withToken($token)->getJson('/api/v1/users')->assertOk()->assertJsonPath('meta.can_assign_roles', false)->assertJsonCount(0, 'meta.roles');
        $this->withToken($token)->postJson("/api/v1/users/{$actor->id}/roles", ['role_ids' => [$adminRole->id]])->assertForbidden();
        $payload = ['name' => 'Blocked admin', 'email' => 'blocked@example.test', 'password' => 'UniquePass123', 'role_ids' => [$adminRole->id]];
        $this->withToken($token)->postJson('/api/v1/users', $payload)->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $payload['role_ids'] = [];
        $this->withToken($token)->postJson('/api/v1/users', $payload)->assertCreated()->assertJsonCount(0, 'data.roles');
        $this->assertFalse($actor->fresh()->hasPermission('role.manage'));
    }

    public function test_new_accounts_require_explicit_password_and_ordinary_users_cannot_save_configuration(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $this->withToken($this->token($admin))->postJson('/api/v1/users', ['name' => 'Missing password', 'email' => 'missing@example.test'])
            ->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'missing@example.test']);
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $token = $this->token($sales);
        foreach (['work-assignment', 'procurement-approval', 'operational-approval', 'sales-quotation-approval', 'ui-settings'] as $setting) {
            $this->withToken($token)->postJson('/api/v1/tenant/'.$setting, [])->assertForbidden();
        }
        $this->withToken($token)->postJson('/api/v1/roles', ['name' => 'Forbidden role'])->assertForbidden();
    }

    public function test_employee_points_respect_own_department_and_company_scope_even_with_user_filter(): void
    {
        $this->seed();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $colleague = User::create(['tenant_id' => $sales->tenant_id, 'name' => 'Colleague', 'email' => 'colleague@example.test', 'password' => 'UniquePass123', 'department_id' => $sales->department_id]);
        KpiAdjustment::where('tenant_id', $sales->tenant_id)->delete();
        foreach ([$sales, $colleague, $admin] as $employee) {
            KpiAdjustment::create(['tenant_id' => $sales->tenant_id, 'user_id' => $employee->id, 'created_by' => $admin->id,
                'adjustment_type' => 'bonus', 'points' => 5, 'period_type' => 'month', 'period_start' => now()->startOfMonth(),
                'period_end' => now()->endOfMonth(), 'reason' => 'Scope test', 'status' => 'approved']);
        }
        $token = $this->token($sales);
        $this->withToken($token)->getJson('/api/v1/kpi/adjustments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $sales->id);
        $this->withToken($token)->getJson('/api/v1/kpi/adjustments?user_id='.$admin->id)->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/kpi/adjustments/summary')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $sales->id);
        $sales->roles()->sync([Role::where('code', 'ROLE-MGR')->value('id')]);
        $this->withToken($token)->getJson('/api/v1/kpi/adjustments')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson('/api/v1/kpi/adjustments/summary')->assertOk()->assertJsonCount(2, 'data');
        $sales->update(['department_id' => null]);
        $this->withToken($token)->getJson('/api/v1/kpi/adjustments')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($this->token($admin))->getJson('/api/v1/kpi/adjustments/summary')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_public_employee_points_page_does_not_embed_any_employee_data(): void
    {
        $this->seed();
        $this->get('/kpi-adjustments')->assertOk()->assertSee('kpiAdjustmentsRoot')
            ->assertDontSee('sales@vk-kpi.local')->assertDontSee('Sales Demo')
            ->assertDontSee('kpiAdjustmentRecords')->assertDontSee('data-server-rendered="1"', false);
    }

    public function test_deactivated_account_cannot_reuse_an_existing_token(): void
    {
        $this->seed();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $token = $this->token($sales);
        $sales->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/v1/kpi/adjustments')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_kpi_configuration_is_not_embedded_publicly_and_requires_management_permission(): void
    {
        $this->seed();
        $this->get('/kpi-settings')->assertOk()->assertSee('kpiSettingsRoot')
            ->assertDontSee('KPI-SALES')->assertDontSee('kpiSettingsData');
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $this->withToken($this->token($sales))->getJson('/api/v1/kpi/definitions')->assertForbidden();
        $this->getJson('/api/v1/kpi/targets')->assertForbidden();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $this->withToken($this->token($admin))->getJson('/api/v1/kpi/definitions')->assertOk();
        $this->getJson('/api/v1/kpi/targets')->assertOk();
    }

    public function test_public_stock_take_page_is_an_authenticated_api_shell(): void
    {
        $this->seed();
        $this->get('/stock-takes')->assertOk()->assertSee('stockTakesRoot')
            ->assertDontSee('stockTakesData')->assertDontSee('ST-00001')
            ->assertDontSee('Warehouse Demo')->assertDontSee('SKU-DEMO');

        $warehouse = User::where('email', 'warehouse@vk-kpi.local')->firstOrFail();
        $this->withToken($this->token($warehouse))->getJson('/api/v1/stock-takes')->assertOk();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $this->withToken($this->token($sales))->getJson('/api/v1/stock-takes')->assertForbidden();
    }

    public function test_public_service_desk_pages_do_not_embed_tenant_customers_or_cases(): void
    {
        $this->seed();
        foreach (['/service-tickets', '/warranty-claims'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('data-service-desk-page', false)
                ->assertDontSee('serviceDeskData')
                ->assertDontSee('CUS-DEMO-001')
                ->assertDontSee('Liên hệ mẫu');
        }

        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $this->withToken($this->token($sales))->getJson('/api/v1/service-desk/lookups')
            ->assertOk()->assertJsonStructure(['data' => ['customers', 'users']]);
    }

    public function test_approval_settings_reject_inactive_foreign_unqualified_and_duplicate_approvers_without_changes(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $director = User::where('email', 'director@vk-kpi.local')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $tenant = \App\Models\Tenant::create(['code' => 'CONFIG-OTHER', 'name' => 'Other company', 'status' => 'active']);
        $foreign = User::create(['tenant_id' => $tenant->id, 'name' => 'Foreign', 'email' => 'foreign-config@example.test', 'password' => 'test12345', 'is_active' => true]);
        $initial = $admin->tenant->ui_settings;
        $this->withToken($this->token($admin));
        foreach ([[$admin->id, $admin->id], [$admin->id, $sales->id], [$admin->id, $foreign->id]] as $ids) {
            $this->postJson('/api/v1/tenant/operational-approval', ['type' => 'purchase_order', 'approvers' => $ids])->assertStatus(422);
            $this->assertEquals($initial, $admin->tenant->fresh()->ui_settings);
        }
        $director->update(['is_active' => false]);
        $this->postJson('/api/v1/tenant/operational-approval', ['type' => 'purchase_order', 'approvers' => [$admin->id, $director->id]])->assertStatus(422);
        $this->postJson('/api/v1/tenant/sales-quotation-approval', ['approvers' => [$foreign->id], 'exception_approvers' => [], 'minimum_margin' => 15, 'maximum_discount' => 0])->assertStatus(422);
        $this->assertEquals($initial, $admin->tenant->fresh()->ui_settings);
    }

    public function test_procurement_and_inventory_permissions_separate_view_create_and_approval_actions(): void
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $role = Role::create([
            'tenant_id' => $actor->tenant_id,
            'code' => 'FLOW-VIEWER',
            'name' => 'Chỉ xem chứng từ vận hành',
            'data_scope' => 'company',
        ]);
        $role->permissions()->sync(Permission::whereIn('code', [
            'procurement.pr.view',
            'procurement.sq.view',
            'procurement.po.view',
            'inventory.receipt.view',
            'inventory.issue.view',
            'inventory.stocktake.view',
        ])->pluck('id'));
        $actor->roles()->sync([$role->id]);
        $token = $this->token($actor);

        foreach (['purchase-requests', 'supplier-quotations', 'purchase-orders', 'goods-receipts', 'goods-issues', 'stock-takes'] as $endpoint) {
            $this->withToken($token)->getJson('/api/v1/'.$endpoint)->assertOk();
        }

        $this->withToken($token)->postJson('/api/v1/purchase-requests', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/supplier-quotations', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/purchase-orders', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/goods-receipts', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/goods-issues', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/stock-takes', [])->assertForbidden();
    }

    public function test_sales_pipeline_view_permission_does_not_allow_mutation(): void
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $role = Role::create(['tenant_id' => $actor->tenant_id, 'code' => 'CRM-VIEWER', 'name' => 'Chỉ xem CRM', 'data_scope' => 'company']);
        $role->permissions()->sync(Permission::whereIn('code', ['sales.lead.view', 'sales.deal.view'])->pluck('id'));
        $actor->roles()->sync([$role->id]);
        $token = $this->token($actor);

        $this->withToken($token)->getJson('/api/v1/leads')->assertOk();
        $this->withToken($token)->getJson('/api/v1/deals')->assertOk();
        $this->withToken($token)->postJson('/api/v1/leads', ['name' => 'Không được tạo'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/deals', ['name' => 'Không được tạo'])->assertForbidden();
    }
}
