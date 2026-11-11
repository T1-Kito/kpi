<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadWorkbenchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_edit_is_owner_scoped_and_completion_notifies_creator(): void
    {
        $this->seed();
        $admin = \App\Models\User::where('email','admin@vk-kpi.local')->firstOrFail();
        $sales = \App\Models\User::where('email','sales@vk-kpi.local')->firstOrFail();
        $adminToken = $this->loginAs($admin->email);
        $salesToken = $this->loginAs($sales->email);
        $foreignLead = $this->withToken($adminToken)->postJson('/api/v1/leads',['name'=>'Riêng Admin','phone'=>'0909666111','assigned_to'=>$admin->id])->assertCreated()->json('data');
        $this->withToken($salesToken)->putJson('/api/v1/leads/'.$foreignLead['id'],['name'=>'Không được sửa','phone'=>'0909666111'])->assertNotFound();
        $lead = $this->withToken($adminToken)->postJson('/api/v1/leads',['name'=>'Giao Sales','phone'=>'0909666222','assigned_to'=>$sales->id])->assertCreated()->json('data');
        $task = Task::where('source_type','Lead')->where('source_id',$lead['id'])->firstOrFail();
        $this->withToken($salesToken)->postJson('/api/v1/tasks/'.$task->id.'/status',['status'=>'completed','reason'=>'Đã gọi, khách hẹn trao đổi ngày mai'])->assertOk();
        $this->assertDatabaseHas('notifications',['recipient_id'=>$admin->id,'source_type'=>'Task','source_id'=>$task->id,'title'=>'Công việc đã hoàn thành']);
    }

    public function test_manual_follow_up_is_new_keeps_vietnam_time_and_requires_result(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $uid = $this->withToken($token)->getJson('/api/v1/me')->json('data.id');
        $lead = $this->withToken($token)->postJson('/api/v1/leads', ['name'=>'TEST lịch hẹn','phone'=>'0909555888','assigned_to'=>$uid])->assertCreated()->json('data');
        $existing = Task::where('source_type','Lead')->where('source_id',$lead['id'])->firstOrFail();
        $date = now()->addDays(2)->format('Y-m-d');
        $new = $this->withToken($token)->postJson("/api/v1/leads/{$lead['id']}/follow-ups", ['title'=>'Hẹn tư vấn riêng','due_at'=>$date.'T10:00'])->assertCreated()->json('data');
        $this->assertNotEquals($existing->id,$new['id']);
        $this->assertSame('03:00', \Illuminate\Support\Carbon::parse($new['due_at'])->utc()->format('H:i'));
        $this->withToken($token)->postJson("/api/v1/leads/{$lead['id']}/follow-ups/{$new['id']}/status", ['status'=>'completed'])->assertStatus(422);
        $this->assertDatabaseHas('tasks',['id'=>$new['id'],'status'=>'new']);
        $this->withToken($token)->postJson("/api/v1/leads/{$lead['id']}/follow-ups/{$new['id']}/status", ['status'=>'completed','reason'=>'Đã gọi, khách xác nhận tham khảo gói đoàn'])->assertOk();
        $this->withToken($token)->putJson('/api/v1/leads/'.$lead['id'], ['name'=>'Tên đã sửa','phone'=>'0909555888'])->assertOk()->assertJsonPath('data.name','Tên đã sửa');
        $this->withToken($token)->postJson('/api/v1/leads', ['name'=>'TEST trùng','phone'=>'+84 909 555 888','assigned_to'=>$uid])->assertStatus(409)->assertJsonPath('duplicates.0.id',$lead['id']);
        $this->assertDatabaseMissing('leads',['name'=>'TEST trùng']);
        $this->withToken($token)->postJson('/api/v1/leads', ['name'=>'TEST trùng','phone'=>'+84 909 555 888','assigned_to'=>$uid,'allow_duplicate'=>true])->assertCreated()->assertJsonPath('meta.duplicate_phone_warning',true);
    }

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
            'name' => 'Email search private', 'phone' => '0918877702', 'email' => 'unique-search@example.test','allow_duplicate'=>true,
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
            'status' => 'completed', 'reason' => 'Đã tư vấn sản phẩm và xác nhận nhu cầu.',
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
