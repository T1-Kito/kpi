<?php

namespace Tests\Feature;

use App\Models\{User,Customer,Lead,Task,Approval};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonnelHandoverTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email): string
    {
        return $this->postJson('/api/v1/auth/login',['email'=>$email,'password'=>'Admin@123'])->assertOk()->json('data.access_token');
    }

    public function test_handover_is_atomic_audited_notified_and_never_moves_approvals(): void
    {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $customer=Customer::create(['tenant_id'=>$admin->tenant_id,'code'=>'HO-C1','name'=>'Handover customer','sales_owner_id'=>$admin->id,'status'=>'active']);
        $lead=Lead::create(['tenant_id'=>$admin->tenant_id,'code'=>'HO-L1','name'=>'Handover lead','phone'=>'0999999999','assigned_to'=>$admin->id,'status'=>'assigned']);
        $task=Task::create(['tenant_id'=>$admin->tenant_id,'code'=>'HO-T1','title'=>'Follow up','module'=>'sales','task_type'=>'lead_follow_up','source_type'=>'Lead','source_id'=>$lead->id,'assignee_id'=>$admin->id,'status'=>'new','created_by'=>$admin->id]);
        $approval=Approval::firstOrFail(); $approval->update(['approver_id'=>$admin->id,'status'=>'pending']);
        $token=$this->login($admin->email); $url='/api/v1/users/'.$admin->id.'/handover';
        $preview=$this->withToken($token)->getJson($url)->assertOk()->json();
        $body=['recipient_id'=>$sales->id,'reason'=>'Chuyển bộ phận','preview_token'=>$preview['meta']['preview_token'],'selection'=>['customers'=>[$customer->id],'leads'=>[$lead->id],'tasks'=>[]]];
        $this->withToken($token)->postJson($url,$body)->assertUnprocessable();
        $this->assertEquals($admin->id,$customer->fresh()->sales_owner_id);
        $body['selection']['tasks']=[$task->id];
        $this->withToken($token)->postJson($url,$body)->assertOk()->assertJsonPath('data.count',3);
        $this->assertEquals($sales->id,$customer->fresh()->sales_owner_id);
        $this->assertEquals($sales->id,$lead->fresh()->assigned_to);
        $this->assertEquals($sales->id,$task->fresh()->assignee_id);
        $this->assertEquals($admin->id,$approval->fresh()->approver_id);
        $this->assertDatabaseHas('audit_logs',['entity_type'=>'personnel_handover','entity_id'=>$admin->id,'reason'=>'Chuyển bộ phận']);
        $this->assertDatabaseHas('notifications',['recipient_id'=>$sales->id,'source_type'=>'PersonnelHandover','status'=>'unread']);
        $this->withToken($token)->postJson($url,$body)->assertConflict();
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(1,'meta.history');
    }

    public function test_handover_rejects_stale_records_inactive_recipient_and_unauthorized_actor(): void
    {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $customer=Customer::create(['tenant_id'=>$admin->tenant_id,'code'=>'HO-C2','name'=>'Before','sales_owner_id'=>$admin->id,'status'=>'active']);
        $url='/api/v1/users/'.$admin->id.'/handover';
        $this->withToken($this->login($sales->email))->getJson($url)->assertForbidden();
        $token=$this->login($admin->email);
        $preview=$this->withToken($token)->getJson($url)->assertOk()->json('meta.preview_token');
        $body=['recipient_id'=>$sales->id,'reason'=>'Transfer','preview_token'=>$preview,'selection'=>['customers'=>[$customer->id]]];
        $customer->update(['name'=>'After']);
        $this->withToken($token)->postJson($url,$body)->assertConflict();
        $this->assertEquals($admin->id,$customer->fresh()->sales_owner_id);
        $body['preview_token']=$this->withToken($token)->getJson($url)->json('meta.preview_token');
        $sales->update(['is_active'=>false]);
        $this->withToken($token)->postJson($url,$body)->assertUnprocessable();
        $sales->update(['is_active'=>true]); $sales->roles()->detach();
        $this->withToken($token)->postJson($url,$body)->assertUnprocessable();
        $body['preview_token']='invalid';
        $this->withToken($token)->postJson($url,$body)->assertUnprocessable();
        $this->assertDatabaseMissing('audit_logs',['entity_type'=>'personnel_handover']);
    }

    public function test_handover_rejects_foreign_tenant_and_ids_not_in_preview(): void
    {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $tenant=\App\Models\Tenant::create(['code'=>'HO-OTHER','name'=>'Other company']);
        $foreign=User::create(['tenant_id'=>$tenant->id,'name'=>'Other user','email'=>'handover.other@example.test','password'=>'StrongPass123','is_active'=>true]);
        $token=$this->login($admin->email);
        $this->withToken($token)->getJson('/api/v1/users/'.$foreign->id.'/handover')->assertNotFound();
        $url='/api/v1/users/'.$sales->id.'/handover';
        $preview=$this->withToken($token)->getJson($url)->assertOk()->json('meta.preview_token');
        $body=['recipient_id'=>$foreign->id,'reason'=>'No cross company transfer','preview_token'=>$preview,'selection'=>['customers'=>[]]];
        $this->withToken($token)->postJson($url,$body)->assertUnprocessable();
        $body['recipient_id']=$admin->id;
        $body['selection']['customers']=[999999];
        $this->withToken($token)->postJson($url,$body)->assertConflict();
        $this->assertDatabaseMissing('audit_logs',['entity_type'=>'personnel_handover']);
    }
}
