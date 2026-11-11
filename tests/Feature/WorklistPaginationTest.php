<?php
namespace Tests\Feature;
use App\Models\{User,Customer,Deal,Contract};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class WorklistPaginationTest extends TestCase {
 use RefreshDatabase;
 public function test_deals_and_contracts_remain_searchable_beyond_one_hundred():void {
  $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();$customer=Customer::where('tenant_id',$admin->tenant_id)->firstOrFail();
  $this->withToken(app(\App\Support\JwtTokenService::class)->issue($admin));
  foreach(['deals'=>Deal::class,'contracts'=>Contract::class] as $endpoint=>$model){
   for($i=0;$i<105;$i++)$model::create(['tenant_id'=>$admin->tenant_id,'code'=>'PAGE-'.$i,'name'=>$i===0?'OLD UNIQUE RECORD':'New '.$i,'owner_id'=>$admin->id,'customer_id'=>$customer->id]);
   $this->getJson('/api/v1/'.$endpoint.'?q=OLD%20UNIQUE%20RECORD&page_size=20')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.code','PAGE-0');
   $this->getJson('/api/v1/'.$endpoint.'?page=6&page_size=20')->assertOk()->assertJsonFragment(['code'=>'PAGE-0']);
  }
 }
}
