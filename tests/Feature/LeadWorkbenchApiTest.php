<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadWorkbenchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_search_finds_email_without_exposing_other_owners(): void
    {
        $this->seed();
        $admin = $this->loginAs('admin@vk-kpi.local');
        $sales = $this->loginAs('sales@vk-kpi.local');
        $salesId = $this->withToken($sales)->getJson('/api/v1/me')->json('data.id');
        $mine = $this->withToken($admin)->postJson('/api/v1/leads', [
            'name' => 'Email search mine', 'phone' => '0918877701', 'email' => 'unique-search@example.test', 'assigned_to' => $salesId,
        ])->assertCreated()->json('data');
        $this->withToken($admin)->postJson('/api/v1/leads', [
            'name' => 'Email search private', 'phone' => '0918877702', 'email' => 'unique-search@example.test',
        ])->assertCreated();
        $this->withToken($sales)->getJson('/api/v1/leads?q=unique-search%40example.test')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['id']);
    }

    public function test_admin_can_see_unassigned_queue_and_assign_with_follow_up_task(): void
    {
        $this->seed();
        $admin = $this->loginAs('admin@vk-kpi.local');
        $sales = $this->loginAs('sales@vk-kpi.local');
        $salesId = $this->withToken($sales)->getJson('/api/v1/me')->json('data.id');
        $lead = $this->withToken($admin)->postJson('/api/v1/leads', [
            'name' => 'Khách chờ phân công', 'phone' => '0909000123', 'source' => 'hotline',
        ])->assertCreated()->json('data');

        $this->withToken($admin)->getJson('/api/v1/leads?scope=unassigned')
            ->assertOk()->assertJsonFragment(['id' => $lead['id']]);
        $this->withToken($admin)->postJson("/api/v1/leads/{$lead['id']}/assign", [
            'assigned_to' => $salesId,
        ])->assertOk()->assertJsonPath('data.assigned_to', $salesId);
        $this->assertDatabaseHas('tasks', [
            'source_type' => 'Lead', 'source_id' => $lead['id'], 'assignee_id' => $salesId,
            'task_type' => 'lead_follow_up', 'status' => 'new',
        ]);
        $this->withToken($sales)->getJson('/api/v1/leads?scope=mine')
            ->assertOk()->assertJsonPath('data.0.id', $lead['id']);
    }

    public function test_sales_cannot_take_someone_elses_lead_or_open_company_queue(): void
    {
        $this->seed();
        $admin = $this->loginAs('admin@vk-kpi.local');
        $sales = $this->loginAs('sales@vk-kpi.local');
        $lead = $this->withToken($admin)->postJson('/api/v1/leads', [
            'name' => 'Lead của quản trị', 'phone' => '0909000456',
            'assigned_to' => $this->withToken($admin)->getJson('/api/v1/me')->json('data.id'),
        ])->assertCreated()->json('data');
        $salesId = $this->withToken($sales)->getJson('/api/v1/me')->json('data.id');
        $this->withToken($sales)->getJson('/api/v1/leads?scope=unassigned')->assertForbidden();
        $this->withToken($sales)->postJson("/api/v1/leads/{$lead['id']}/assign", [
            'assigned_to' => $salesId,
        ])->assertForbidden();
    }

    public function test_reassign_cancels_previous_open_follow_up_instead_of_leaving_two_active_tasks(): void
    {
        $this->seed();
        $admin = $this->loginAs('admin@vk-kpi.local');
        $adminId = $this->withToken($admin)->getJson('/api/v1/me')->json('data.id');
        $salesId = $this->withToken($this->loginAs('sales@vk-kpi.local'))->getJson('/api/v1/me')->json('data.id');
        $lead = $this->withToken($admin)->postJson('/api/v1/leads', [
            'name' => 'Lead chuyển người', 'phone' => '0909000789', 'assigned_to' => $adminId,
        ])->assertCreated()->json('data');
        $this->withToken($admin)->postJson("/api/v1/leads/{$lead['id']}/assign", ['assigned_to' => $salesId])->assertOk();
        $this->assertSame(1, Task::where('source_type', 'Lead')->where('source_id', $lead['id'])->where('status', 'new')->count());
        $this->assertSame(1, Task::where('source_type', 'Lead')->where('source_id', $lead['id'])->where('status', 'cancelled')->count());
    }

    public function test_lead_follow_up_can_be_created_completed_and_preserved_after_conversion(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $userId = $this->withToken($token)->getJson('/api/v1/me')->json('data.id');
        $lead = $this->withToken($token)->postJson('/api/v1/leads', [
            'name' => 'Khách cần chăm sóc', 'phone' => '0909555123', 'assigned_to' => $userId,
        ])->assertCreated()->json('data');

        $task = $this->withToken($token)->postJson("/api/v1/leads/{$lead['id']}/follow-ups", [
            'title' => 'Gọi tư vấn sản phẩm', 'due_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.assignee_id', $userId)->json('data');
        $this->withToken($token)->postJson("/api/v1/leads/{$lead['id']}/follow-ups/{$task['id']}/status", [
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $converted = $this->withToken($token)->postJson("/api/v1/leads/{$lead['id']}/qualify", [
            'deal_name' => 'Cơ hội từ chăm sóc',
        ])->assertOk()->json('data');
        $this->withToken($token)->getJson("/api/v1/leads/{$lead['id']}")
            ->assertOk()
            ->assertJsonPath('data.converted_customer.id', $converted['customer']['id'])
            ->assertJsonPath('data.converted_deal.id', $converted['deal']['id']);
        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'status' => 'completed']);
        $this->withToken($token)->postJson("/api/v1/leads/{$lead['id']}/follow-ups", [
            'title' => 'Không được tạo', 'due_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(409);
    }

    public function test_follow_up_status_cannot_be_changed_through_another_lead(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $userId = $this->withToken($token)->getJson('/api/v1/me')->json('data.id');
        $first = $this->withToken($token)->postJson('/api/v1/leads', ['name' => 'Lead A', 'phone' => '0909555001', 'assigned_to' => $userId])->assertCreated()->json('data');
        $second = $this->withToken($token)->postJson('/api/v1/leads', ['name' => 'Lead B', 'phone' => '0909555002', 'assigned_to' => $userId])->assertCreated()->json('data');
        $task = $this->withToken($token)->postJson("/api/v1/leads/{$first['id']}/follow-ups", [
            'title' => 'Gọi Lead A', 'due_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('data');
        $this->withToken($token)->postJson("/api/v1/leads/{$second['id']}/follow-ups/{$task['id']}/status", [
            'status' => 'completed',
        ])->assertNotFound();
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
