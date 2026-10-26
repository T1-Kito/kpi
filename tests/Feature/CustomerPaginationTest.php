<?php
namespace Tests\Feature;
use App\Models\{Customer,User,Task};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPaginationTest extends TestCase {
    use RefreshDatabase;
    public function test_search_and_status_cover_records_beyond_first_hundred_and_preserve_scope(): void {
        $this->seed();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $old=Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'OLD-SEARCH','name'=>'Old customer','email'=>'old-search@example.test','status'=>'inactive','sales_owner_id'=>$sales->id,'created_by'=>$sales->id]);
        for($i=0;$i<105;$i++) Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'PAGE-'.$i,'name'=>'Recent '.$i,'status'=>'active','sales_owner_id'=>$sales->id,'created_by'=>$sales->id]);
        $token=$this->postJson('/api/v1/auth/login',['email'=>$sales->email,'password'=>'Admin@123'])->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/customers?q=old-search@example.test&status=inactive')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$old->id);
        $this->withToken($token)->getJson('/api/v1/customers?status=inactive')->assertOk()->assertJsonPath('meta.total',1);
        $first=$this->withToken($token)->getJson('/api/v1/customers?page_size=20')->assertOk()->json();
        $this->assertGreaterThan(5,$first['meta']['last_page']);
        $last=$this->withToken($token)->getJson('/api/v1/customers?page_size=20&page='.$first['meta']['last_page'])->assertOk()->json('data');
        $this->assertContains($old->id,array_column($last,'id'));
        $old->update(['sales_owner_id'=>User::where('email','admin@vk-kpi.local')->value('id')]);
        $this->withToken($token)->getJson('/api/v1/customers?q=old-search@example.test')->assertOk()->assertJsonCount(1,'data');
    }
    public function test_care_history_can_reach_records_older_than_thirty(): void {
        $this->seed(); $user=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $customer=Customer::create(['tenant_id'=>$user->tenant_id,'code'=>'HISTORY-PAGE','name'=>'History','status'=>'active','sales_owner_id'=>$user->id,'created_by'=>$user->id]);
        for($i=0;$i<35;$i++) Task::create(['tenant_id'=>$user->tenant_id,'code'=>'HISTORY-'.$i,'title'=>'Call '.$i,'module'=>'sales','task_type'=>'customer_follow_up','source_type'=>'Customer','source_id'=>$customer->id,'assignee_id'=>$user->id,'status'=>'new','created_by'=>$user->id]);
        $token=$this->postJson('/api/v1/auth/login',['email'=>$user->email,'password'=>'Admin@123'])->json('data.access_token');
        $url='/api/v1/customers/'.$customer->id.'/care';
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(30,'data.tasks')->assertJsonPath('meta.total',35)->assertJsonPath('meta.last_page',2);
        $this->withToken($token)->getJson($url.'?page=2')->assertOk()->assertJsonCount(5,'data.tasks')->assertJsonPath('data.tasks.4.code','HISTORY-0');
        $this->withToken($token)->getJson($url.'?page=0')->assertUnprocessable();
    }
}
