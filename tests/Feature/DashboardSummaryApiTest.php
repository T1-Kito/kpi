<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_load_dashboard_summary_in_one_request(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');

        $this->withToken($token)
            ->getJson('/api/v1/dashboard/summary?type=admin')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'tasks' => ['data', 'meta'],
                    'alerts' => ['data', 'meta'],
                    'leads' => ['data', 'meta'],
                    'quotations' => ['data', 'meta'],
                    'pendingQuotes' => ['data', 'meta'],
                    'salesOrders' => ['data', 'meta'],
                    'balances' => ['data', 'meta'],
                    'purchaseRequests' => ['data', 'meta'],
                    'purchaseOrders' => ['data', 'meta'],
                    'executive' => [
                        'generated_at', 'governance' => ['unassigned_leads', 'customers_without_owner', 'organizations_without_contact', 'duplicate_reviews', 'sales_quotes_pending'],
                        'period', 'revenue', 'previous_revenue', 'receivables',
                        'inventory_value', 'monthly_revenue', 'funnel', 'top_customers', 'low_stock_items',
                        'receivable_aging', 'purchase', 'operations', 'activity', 'recent_orders',
                    ],
                ],
            ]);
    }

    public function test_executive_summary_includes_customer_and_products_for_recent_orders(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');

        $response = $this->withToken($token)
            ->getJson('/api/v1/dashboard/summary?type=admin&month=2026-10')
            ->assertOk()
            ->assertJsonPath('data.executive.period', '2026-10');

        $order = collect($response->json('data.executive.recent_orders'))->firstWhere('code', 'SO-DEMO-001');
        $this->assertNotNull($order);
        $this->assertNotEmpty($order['customer_name']);
        $this->assertNotEmpty($order['items']);
        $this->assertNotEmpty($order['items'][0]['name']);
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }

    public function test_custom_executive_role_can_read_company_summary_without_admin_mutation_rights(): void
    {
        $this->seed();
        $user = \App\Models\User::where('email', 'director@vk-kpi.local')->firstOrFail();
        $role = \App\Models\Role::create(['tenant_id' => $user->tenant_id, 'code' => 'CHAIR-READ', 'name' => 'Chair read only', 'data_scope' => 'company']);
        $role->permissions()->sync(\App\Models\Permission::where('code', 'dashboard.executive.view')->pluck('id'));
        $user->roles()->sync([$role->id]);
        $token = $this->loginAs($user->email);
        $this->withToken($token)->getJson('/api/v1/dashboard/summary?type=director')->assertOk()->assertJsonStructure(['data' => ['executive' => ['period', 'revenue']]]);
        $this->postJson('/api/v1/tenant/work-assignment', [])->assertForbidden();
        $this->postJson('/api/v1/customers', ['name' => 'Not authorized'])->assertForbidden();
    }

    public function test_role_name_or_query_parameter_cannot_bypass_executive_permission(): void
    {
        $this->seed();
        $user = \App\Models\User::where('email', 'director@vk-kpi.local')->firstOrFail();
        $user->roles->first()->permissions()->detach(\App\Models\Permission::where('code', 'dashboard.executive.view')->value('id'));
        $token = $this->loginAs($user->email);
        $response = $this->withToken($token)->getJson('/api/v1/dashboard/summary?type=director')->assertOk();
        $this->assertArrayNotHasKey('executive', $response->json('data'));
        $token = $this->loginAs('sales@vk-kpi.local');
        $response = $this->withToken($token)->getJson('/api/v1/dashboard/summary?type=admin')->assertOk();
        $this->assertArrayNotHasKey('executive', $response->json('data'));
    }
}
