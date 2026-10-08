<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesDocumentPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_quotation_view_does_not_allow_create_or_edit(): void
    {
        $this->seed();
        $user = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $role = Role::where('tenant_id', $user->tenant_id)->where('code', 'ROLE-SALES')->firstOrFail();
        $role->permissions()->sync(Permission::where('code', 'sales.quotation.view')->pluck('id'));
        $token = $this->loginAs('sales@vk-kpi.local');

        $this->withToken($token)->getJson('/api/v1/quotations')->assertOk();
        $this->withToken($token)->postJson('/api/v1/quotations', [])->assertForbidden();
        $this->withToken($token)->putJson('/api/v1/quotations/1', [])->assertForbidden();
        $this->withToken($token)->deleteJson('/api/v1/quotations/1')->assertForbidden();
    }

    public function test_destructive_document_permissions_are_not_granted_to_sales_by_default(): void
    {
        $this->seed();
        $role = Role::where('code', 'ROLE-SALES')->firstOrFail();
        $this->assertFalse($role->permissions()->where('code', 'sales.quotation.delete')->exists());
        $this->assertFalse($role->permissions()->where('code', 'sales.order.delete')->exists());
    }

    public function test_order_edit_and_delete_are_separate_from_view(): void
    {
        $this->seed();
        $user = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $role = Role::where('tenant_id', $user->tenant_id)->where('code', 'ROLE-SALES')->firstOrFail();
        $role->permissions()->sync(Permission::where('code', 'sales.order.view')->pluck('id'));
        $token = $this->loginAs('sales@vk-kpi.local');

        $this->withToken($token)->getJson('/api/v1/sales-orders')->assertOk();
        $this->withToken($token)->putJson('/api/v1/sales-orders/1', ['payment_terms' => '30 ngày'])->assertForbidden();
        $this->withToken($token)->deleteJson('/api/v1/sales-orders/1')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/sales-orders', [])->assertForbidden();
    }

    public function test_draft_order_can_be_edited_and_deleted_by_permitted_user(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $order = SalesOrder::where('status', 'draft')->whereDoesntHave('deliveries')->whereDoesntHave('invoices')->firstOrFail();

        $this->withToken($token)->putJson("/api/v1/sales-orders/{$order->id}", ['payment_terms' => '30 ngày'])
            ->assertOk()->assertJsonPath('data.payment_terms', '30 ngày');
        $this->withToken($token)->deleteJson("/api/v1/sales-orders/{$order->id}")->assertOk();
        $this->assertDatabaseMissing('sales_orders', ['id' => $order->id]);
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Admin@123'])->json('data.access_token');
    }
}
