<?php
namespace Tests\Feature;
use App\Models\{User,Task};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class TaskListPaginationTest extends TestCase
{
    use RefreshDatabase;
    public function test_followup_beyond_first_hundred_is_searchable_paginated_and_scoped():void {
        $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();$sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $this->withToken(app(\App\Support\JwtTokenService::class)->issue($admin));
        for($i=0;$i<105;$i++)Task::create(['tenant_id'=>$admin->tenant_id,'code'=>'OLD-'.$i,'title'=>'Việc cũ '.$i,'module'=>'sales','task_type'=>'manual','status'=>'new','priority'=>'normal','created_by'=>$admin->id,'assignee_id'=>$admin->id,'due_at'=>now()->subDays(10)]);
        $lead=$this->postJson('/api/v1/leads',['name'=>'Lead kiểm tra việc','phone'=>'0912360002','assigned_to'=>$admin->id])->assertCreated()->json('data');
        $title='TEST RETEST 31-10 HEN 10H';
        $task=$this->postJson('/api/v1/leads/'.$lead['id'].'/follow-ups',['title'=>$title,'due_at'=>now()->addDays(2)->format('Y-m-d').'T10:00'])->assertCreated()->json('data');
        $this->getJson('/api/v1/leads/'.$lead['id'])->assertOk()->assertJsonFragment(['title'=>$title]);
        $this->getJson('/api/v1/tasks?q='.urlencode($title).'&page_size=20')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$task['id']);
        $this->getJson('/api/v1/tasks?page=6&page_size=20')->assertOk()->assertJsonFragment(['title'=>$title])->assertJsonPath('meta.last_page',6);
        $this->getJson('/api/v1/tasks?q='.urlencode($title).'&priority=high')->assertOk()->assertJsonCount(0,'data');
        $response=$this->withToken(app(\App\Support\JwtTokenService::class)->issue($sales))->getJson('/api/v1/tasks?q='.urlencode($title))->assertOk()->assertJsonCount(0,'data');
        $this->assertLessThan(105,$response->json('meta.stats.all'));
    }
}
