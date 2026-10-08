<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\{LeadService, TaskService, WorkAssignmentService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_rotates_preserves_explicit_owner_and_skips_inactive_users(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $settings = $admin->tenant->ui_settings ?? [];
        $settings['work_assignment'] = ['enabled' => true, 'pools' => ['lead' => [$admin->id, $sales->id]]];
        $admin->tenant->update(['ui_settings' => $settings]);
        $service = app(LeadService::class);
        $first = $service->create($admin, ['name' => 'One', 'phone' => '0912000101']);
        $second = $service->create($admin, ['name' => 'Two', 'phone' => '0912000102']);
        $this->assertSame($admin->id, $first->assigned_to);
        $this->assertSame($sales->id, $second->assigned_to);
        $this->assertSame('assigned', $second->status);
        $explicit = $service->create($admin, ['name' => 'Explicit', 'phone' => '0912000103', 'assigned_to' => $sales->id]);
        $this->assertSame($sales->id, $explicit->assigned_to);
        $sales->update(['is_active' => false]);
        $this->assertSame($admin->id, app(WorkAssignmentService::class)->pick($admin->tenant_id, 'lead'));
        $admin->update(['is_active' => false]);
        $this->assertNull(app(WorkAssignmentService::class)->pick($admin->tenant_id, 'lead'));
    }

    public function test_generated_active_work_is_not_duplicated_but_can_reopen_after_completion(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $data = ['title' => 'Automatic work', 'task_type' => 'warehouse_issue', 'module' => 'inventory', 'source_type' => 'SalesOrder', 'source_id' => 99999];
        $service = app(TaskService::class);
        $first = $service->create($admin, $data);
        $second = $service->create($admin, $data);
        $this->assertSame($first->id, $second->id);
        $this->assertNull($first->assignee_id);
        $this->assertSame(1, $first->history()->count());
        $first->update(['status' => 'completed']);
        $this->assertNotSame($first->id, $service->create($admin, $data)->id);
    }

    public function test_task_pool_checks_current_permission_and_tenant_and_keeps_manual_work_separate(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $warehouse = User::where('email', 'warehouse@vk-kpi.local')->firstOrFail();
        $foreignTenant = \App\Models\Tenant::create(['code' => 'OTHER-AUTO', 'name' => 'Other company']);
        $foreign = User::create(['tenant_id' => $foreignTenant->id, 'name' => 'Other user', 'email' => 'other-auto@example.test', 'password' => 'password', 'is_active' => true]);
        $foreign->roles()->sync($admin->roles()->pluck('roles.id'));
        $settings = $admin->tenant->ui_settings ?? [];
        $settings['work_assignment'] = ['enabled' => true, 'pools' => ['warehouse_issue' => [$warehouse->id, $foreign->id]]];
        $admin->tenant->update(['ui_settings' => $settings]);
        $data = ['title' => 'Warehouse', 'task_type' => 'warehouse_issue', 'module' => 'inventory', 'source_type' => 'SalesOrder', 'source_id' => 88888];
        $service = app(TaskService::class);
        $task = $service->create($admin, $data);
        $this->assertSame($warehouse->id, $task->assignee_id);
        $this->assertDatabaseHas((new \App\Models\VkNotification)->getTable(), ['source_type' => 'Task', 'source_id' => $task->id, 'recipient_id' => $warehouse->id]);
        $manual = $service->create($admin, $data, false);
        $this->assertNotSame($task->id, $manual->id);
        $this->assertNull($manual->assignee_id);
        $warehouse->roles()->detach();
        $this->assertNull(app(WorkAssignmentService::class)->pick($admin->tenant_id, 'warehouse_issue'));
    }

    public function test_only_admin_can_configure_and_ineligible_recipient_is_rejected(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $payload = ['enabled' => true, 'pools' => ['lead' => [$sales->id], 'warehouse_issue' => [], 'goods_receipt' => [], 'purchase_request' => []]];
        $salesToken = $this->postJson('/api/v1/auth/login', ['email' => $sales->email, 'password' => 'Admin@123'])->json('data.access_token');
        $this->withToken($salesToken)->postJson('/api/v1/tenant/work-assignment', $payload)->assertForbidden();
        $adminToken = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'Admin@123'])->json('data.access_token');
        $this->withToken($adminToken)->postJson('/api/v1/tenant/work-assignment', $payload)->assertOk();
        $this->getJson('/api/v1/tenant/work-assignment')->assertOk()->assertJsonPath('data.enabled', true);
        $payload['pools']['warehouse_issue'] = [$sales->id];
        $this->postJson('/api/v1/tenant/work-assignment', $payload)->assertUnprocessable();
        $sales->update(['is_active' => false]);
        $payload['pools']['warehouse_issue'] = [];
        $this->postJson('/api/v1/tenant/work-assignment', $payload)->assertUnprocessable();
    }
}
