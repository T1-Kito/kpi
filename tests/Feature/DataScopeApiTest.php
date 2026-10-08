<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Sku;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataScopeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_user_only_sees_owned_customer_records(): void
    {
        $this->seed();
        $salesToken = $this->loginAs('sales@vk-kpi.local');
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();

        $hiddenCustomer = Customer::create([
            'tenant_id' => $admin->tenant_id,
            'code' => 'CUS-HIDDEN',
            'name' => 'Khach hang cua admin',
            'phone' => '0999000000',
            'sales_owner_id' => $admin->id,
            'status' => 'active',
        ]);

        $this->withToken($salesToken)
            ->getJson('/api/v1/customers?q=CUS&page_size=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->withToken($salesToken)
            ->getJson("/api/v1/customers/{$hiddenCustomer->id}")
            ->assertNotFound();
    }

    public function test_warehouse_user_only_sees_managed_warehouse_inventory(): void
    {
        $this->seed();
        $warehouseToken = $this->loginAs('warehouse@vk-kpi.local');
        $tenantId = User::where('email', 'warehouse@vk-kpi.local')->value('tenant_id');
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $product = Product::where('code', 'PROD-PRINTER')->firstOrFail();

        $otherWarehouse = Warehouse::create([
            'tenant_id' => $tenantId,
            'code' => 'WH-HN',
            'name' => 'Kho Ha Noi',
            'manager_id' => $admin->id,
            'status' => 'active',
        ]);
        $otherSku = Sku::create([
            'tenant_id' => $tenantId,
            'product_id' => $product->id,
            'sku_code' => 'SKU-HIDDEN',
            'name' => 'SKU kho khac',
            'unit' => 'pcs',
            'sale_price' => 100000,
            'cost_price' => 80000,
            'status' => 'active',
        ]);
        InventoryBalance::create([
            'tenant_id' => $tenantId,
            'warehouse_id' => $otherWarehouse->id,
            'sku_id' => $otherSku->id,
            'lot_no' => 'OPENING',
            'on_hand' => 10,
            'reserved' => 0,
            'available' => 10,
        ]);

        $this->withToken($warehouseToken)
            ->getJson('/api/v1/inventory-balances?page_size=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        \App\Models\InventoryTransaction::create([
            'tenant_id' => $tenantId, 'warehouse_id' => $otherWarehouse->id, 'sku_id' => $otherSku->id,
            'lot_no' => 'HIDDEN', 'transaction_type' => 'receipt', 'quantity' => 10,
            'source_type' => 'System', 'source_id' => 0,
        ]);
        $this->withToken($warehouseToken)->getJson('/api/v1/inventory-transactions?warehouse_id='.$otherWarehouse->id)
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }

    public function test_sales_cannot_change_another_users_task_by_guessing_id(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $task = app(\App\Support\TaskService::class)->create($admin, [
            'title' => 'Private task', 'task_type' => 'manual', 'assignee_id' => $admin->id,
        ]);
        $this->withToken($this->loginAs('sales@vk-kpi.local'))
            ->postJson("/api/v1/tasks/{$task->id}/status", ['status' => 'completed'])->assertNotFound();
        $this->assertSame('new', $task->fresh()->status);
        $this->withToken($this->loginAs($admin->email))
            ->postJson("/api/v1/tasks/{$task->id}/status", ['status' => 'completed'])->assertOk();
    }

    public function test_missing_department_does_not_expose_other_unallocated_records(): void
    {
        $this->seed();
        $actor = User::where('email', 'procurement@vk-kpi.local')->firstOrFail();
        $actor->update(['department_id' => null]);
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $admin->update(['department_id' => null]);
        $task = app(\App\Support\TaskService::class)->create($admin, ['title' => 'Not shared', 'task_type' => 'manual', 'assignee_id' => $admin->id]);
        $this->assertFalse(\App\Support\DataScope::task(\App\Models\Task::whereKey($task->id), $actor)->exists());
        $customer = Customer::create(['tenant_id' => $actor->tenant_id, 'code' => 'NO-DEPARTMENT', 'name' => 'Private customer', 'sales_owner_id' => $admin->id]);
        $this->assertFalse(\App\Support\DataScope::owned(Customer::whereKey($customer->id), $actor, 'sales_owner_id', 'salesOwner')->exists());
    }

    public function test_warehouse_queue_does_not_include_tasks_assigned_to_other_departments(): void
    {
        $this->seed();
        $warehouse = User::where('email', 'warehouse@vk-kpi.local')->firstOrFail();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $service = app(\App\Support\TaskService::class);
        $private = $service->create($admin, ['title' => 'Other owner', 'task_type' => 'warehouse_issue', 'assignee_id' => $admin->id]);
        $queue = $service->create($admin, ['title' => 'Unassigned issue', 'task_type' => 'warehouse_issue']);
        $this->assertFalse(\App\Support\DataScope::task(\App\Models\Task::whereKey($private->id), $warehouse)->exists());
        $this->assertTrue(\App\Support\DataScope::task(\App\Models\Task::whereKey($queue->id), $warehouse)->exists());
    }
}
