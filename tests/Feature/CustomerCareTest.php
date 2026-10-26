<?php
namespace Tests\Feature;
use App\Models\{Customer,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCareTest extends TestCase {
    use RefreshDatabase;
    public function test_no_answer_requires_follow_up_and_completion_is_atomic_repeat_safe(): void {
        $this->seed();$sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $customer=Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'CARE-NEXT','name'=>'Next','created_by'=>$sales->id,'sales_owner_id'=>$sales->id,'status'=>'active']);
        $token=$this->postJson('/api/v1/auth/login',['email'=>$sales->email,'password'=>'Admin@123'])->json('data.access_token');
        $url='/api/v1/customers/'.$customer->id.'/care';
        $task=$this->withToken($token)->postJson($url,['title'=>'Gọi khách','due_at'=>now()->addDay()->toIso8601String()])->assertCreated()->json('data');
        $complete=$url.'/'.$task['id'].'/complete';
        $body=['outcome'=>'no_answer','note'=>'Chưa liên hệ được'];
        $this->withToken($token)->postJson($complete,$body)->assertUnprocessable();
        $this->assertDatabaseHas('tasks',['id'=>$task['id'],'status'=>'new']);
        $body+=['next_title'=>'Gọi lại','next_due_at'=>now()->addDays(2)->toIso8601String()];
        $sales->update(['is_active'=>false]);
        $adminToken=$this->postJson('/api/v1/auth/login',['email'=>'admin@vk-kpi.local','password'=>'Admin@123'])->json('data.access_token');
        $before=\App\Models\Task::count();
        $this->withToken($adminToken)->postJson($complete,$body)->assertUnprocessable();
        $this->assertSame($before,\App\Models\Task::count());
        $this->assertDatabaseHas('tasks',['id'=>$task['id'],'status'=>'new']);
        $sales->update(['is_active'=>true]);
        $token=$this->postJson('/api/v1/auth/login',['email'=>$sales->email,'password'=>'Admin@123'])->json('data.access_token');
        $next=$this->withToken($token)->postJson($complete,$body)->assertOk()->json('next_task');
        $this->assertDatabaseHas('tasks',['id'=>$next['id'],'source_id'=>$customer->id,'assignee_id'=>$sales->id,'status'=>'new']);
        $this->assertDatabaseHas('tasks',['id'=>$task['id'],'status'=>'completed']);
        $count=\App\Models\Task::count();
        $this->withToken($token)->postJson($complete,$body)->assertConflict();
        $this->assertSame($count,\App\Models\Task::count());
    }

    public function test_board_is_scoped_paginated_and_excludes_inactive_customers(): void {
        $this->seed();$sales=User::where('email','sales@vk-kpi.local')->firstOrFail();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $mine=Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'BOARD-MINE','name'=>'Mine','created_by'=>$sales->id,'sales_owner_id'=>$sales->id,'status'=>'active']);
        $other=Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'BOARD-OTHER','name'=>'Other','created_by'=>$admin->id,'sales_owner_id'=>$admin->id,'status'=>'active']);
        $token=$this->postJson('/api/v1/auth/login',['email'=>$sales->email,'password'=>'Admin@123'])->json('data.access_token');
        $rows=$this->withToken($token)->getJson('/api/v1/customers/care-board?bucket=unscheduled')->assertOk()->json('data');
        $this->assertContains($mine->id,array_column($rows,'id'));$this->assertNotContains($other->id,array_column($rows,'id'));
        $task=$this->withToken($token)->postJson('/api/v1/customers/'.$mine->id.'/care',['title'=>'Board task','due_at'=>now()->addDays(2)->toIso8601String()])->assertCreated()->json('data');
        $rows=$this->withToken($token)->getJson('/api/v1/customers/care-board?bucket=unscheduled')->assertOk()->json('data');$this->assertNotContains($mine->id,array_column($rows,'id'));
        $this->withToken($token)->getJson('/api/v1/customers/care-board?bucket=upcoming')->assertOk()->assertJsonPath('data.0.customer.id',$mine->id);
        \App\Models\Task::whereKey($task['id'])->update(['due_at'=>now()->subMinute()]);
        $this->withToken($token)->getJson('/api/v1/customers/care-board?bucket=overdue')->assertOk()->assertJsonPath('data.0.id',$task['id']);
        $mine->update(['status'=>'inactive']);
        $this->withToken($token)->getJson('/api/v1/customers/care-board?bucket=overdue')->assertOk()->assertJsonPath('meta.total',0);
        $this->withToken($token)->getJson('/api/v1/customers/care-board?bucket=invalid')->assertUnprocessable();
    }
    public function test_care_is_scoped_scheduled_notified_and_results_are_repeat_safe(): void {
        $this->seed();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $customer=Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'CARE-1','name'=>'Care customer','created_by'=>$sales->id,'sales_owner_id'=>$sales->id,'status'=>'active']);
        $token=$this->postJson('/api/v1/auth/login',['email'=>$sales->email,'password'=>'Admin@123'])->json('data.access_token');
        $url='/api/v1/customers/'.$customer->id.'/care';
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.next_task',null);
        $task=$this->withToken($token)->postJson($url,['title'=>'Call customer','due_at'=>now()->addDay()->toIso8601String()])->assertCreated()->json('data');
        $this->assertEquals($sales->id,$task['assignee_id']);
        $this->assertDatabaseHas('notifications',['source_type'=>'Task','source_id'=>$task['id'],'recipient_id'=>$sales->id]);
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.next_task.id',$task['id']);
        $this->withToken($token)->postJson($url.'/'.$task['id'].'/complete',['outcome'=>'contacted'])->assertUnprocessable();
        $body=['outcome'=>'contacted','note'=>'Customer requested a quote'];
        $this->withToken($token)->postJson($url.'/'.$task['id'].'/complete',$body)->assertOk();
        $this->withToken($token)->postJson($url.'/'.$task['id'].'/complete',$body)->assertConflict();
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.next_task',null)->assertJsonPath('data.last_result','Đã liên hệ: Customer requested a quote');
        $customer->update(['sales_owner_id'=>$admin->id]);
        // Assignment does not revoke the creator's visibility under catalog sharing.
        $this->withToken($token)->getJson($url)->assertOk();
        $this->assertSame($sales->id,$customer->fresh()->created_by);
        $private=Customer::create(['tenant_id'=>$admin->tenant_id,'code'=>'CARE-PRIVATE','name'=>'Private customer','created_by'=>$admin->id,'sales_owner_id'=>$admin->id,'status'=>'active']);
        $url='/api/v1/customers/'.$private->id.'/care';
        $this->withToken($token)->getJson($url)->assertNotFound();
        $this->withToken($token)->postJson($url,['title'=>'Blocked','due_at'=>now()->addDay()->toIso8601String()])->assertNotFound();
    }
    public function test_care_blocks_unassigned_inactive_and_past_schedule_and_counts_overdue(): void {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $customer=Customer::create(['tenant_id'=>$admin->tenant_id,'code'=>'CARE-EDGE','name'=>'Care edge','status'=>'active']);
        $token=$this->postJson('/api/v1/auth/login',['email'=>$admin->email,'password'=>'Admin@123'])->json('data.access_token');
        $url='/api/v1/customers/'.$customer->id.'/care';
        $payload=['title'=>'Follow up','due_at'=>now()->addDay()->toIso8601String()];
        $this->withToken($token)->postJson($url,$payload)->assertUnprocessable();
        $customer->update(['sales_owner_id'=>$sales->id]); $sales->update(['is_active'=>false]);
        $this->withToken($token)->postJson($url,$payload)->assertUnprocessable();
        $sales->update(['is_active'=>true]);
        $this->withToken($token)->postJson($url,[...$payload,'due_at'=>now()->subDay()->toIso8601String()])->assertUnprocessable();
        $task=$this->withToken($token)->postJson($url,$payload)->assertCreated()->json('data');
        \App\Models\Task::whereKey($task['id'])->update(['due_at'=>now()->subHour()]);
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.overdue_count',1)->assertJsonPath('data.next_task.id',$task['id']);
        $other=Customer::create(['tenant_id'=>$admin->tenant_id,'code'=>'CARE-OTHER','name'=>'Other','created_by'=>$admin->id,'sales_owner_id'=>$admin->id,'status'=>'active']);
        $this->withToken($token)->postJson('/api/v1/customers/'.$other->id.'/care/'.$task['id'].'/complete',['outcome'=>'contacted','note'=>'Wrong customer'])->assertNotFound();
        $this->assertDatabaseHas('tasks',['id'=>$task['id'],'status'=>'new']);
    }
}
