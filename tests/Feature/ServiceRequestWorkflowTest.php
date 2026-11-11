<?php
namespace Tests\Feature;
use App\Models\{User,Customer,Department,ServiceTicket};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ServiceRequestWorkflowTest extends TestCase {
 use RefreshDatabase;
 private function actor():User{$this->seed();$actor=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->withToken(app(\App\Support\JwtTokenService::class)->issue($actor));return $actor;}
 private function payload(User $actor,string $category):array{return ['customer_id'=>Customer::where('tenant_id',$actor->tenant_id)->firstOrFail()->id,'subject'=>'Kiểm tra CSKH','category'=>$category,'priority'=>'normal'];}
 public function test_routing_applies_only_new_tickets_and_validates_department():void{
  $actor=$this->actor();$dept=Department::create(['tenant_id'=>$actor->tenant_id,'code'=>'CARE','name'=>'CSKH']);$actor->update(['department_id'=>$dept->id]);
  $rule=['department_id'=>$dept->id,'assignee_id'=>$actor->id,'coordinator_id'=>$actor->id,'supervisor_id'=>$actor->id,'response_minutes'=>30,'resolution_minutes'=>120];
  $this->putJson('/api/v1/service-desk/routing',['routing'=>['lost_property'=>$rule]])->assertOk();
  $ticket=$this->postJson('/api/v1/service-tickets',$this->payload($actor,'lost_property')+['request_details'=>['item_description'=>'Ví màu đen','lost_location'=>'Công viên','contact_phone'=>'0901234567']])->assertCreated()->assertJsonPath('data.department_id',$dept->id)->assertJsonPath('data.request_details.item_description','Ví màu đen')->json('data');
  $stored=ServiceTicket::findOrFail($ticket['id']);$this->assertEqualsWithDelta(120,now()->diffInMinutes($stored->resolution_due_at),1);
  $rule['resolution_minutes']=240;$this->putJson('/api/v1/service-desk/routing',['routing'=>['lost_property'=>$rule]])->assertOk();$this->assertTrue($stored->resolution_due_at->equalTo($stored->fresh()->resolution_due_at));
  $other=Department::create(['tenant_id'=>$actor->tenant_id,'code'=>'OTHER','name'=>'Khác']);$rule['department_id']=$other->id;$this->putJson('/api/v1/service-desk/routing',['routing'=>['lost_property'=>$rule]])->assertUnprocessable();
  $this->putJson('/api/v1/service-desk/routing',['routing'=>['unknown'=>$rule]])->assertUnprocessable();
 }
 public function test_closing_requires_group_result_and_cannot_change_stock_or_money():void{
  $actor=$this->actor();$ticket=$this->postJson('/api/v1/service-tickets',$this->payload($actor,'online_payment')+['assignee_id'=>$actor->id,'request_details'=>['online_order'=>'WEB-1','amount'=>100000]])->assertCreated()->json('data');
  $url='/api/v1/service-tickets/'.$ticket['id'];
  $this->putJson($url,['status'=>'resolved','resolution_code'=>'REFUND','resolution_note'=>'Đã đối soát và hoàn tiền'])->assertOk();
  $this->postJson($url.'/worklogs',['visibility'=>'public','content'=>'Đã gọi xác nhận với khách'])->assertOk();
  $close=['status'=>'closed','customer_confirmation'=>'accepted','resolution_note'=>'Khách đã nhận'];
  $this->putJson($url,$close)->assertUnprocessable();
  $before=\Illuminate\Support\Facades\DB::table('inventory_balances')->sum('quantity_on_hand');
  $this->putJson($url,$close+['request_details'=>['outcome'=>'refunded','result_reference'=>'PC-TEST']])->assertOk()->assertJsonPath('data.request_details.online_order','WEB-1')->assertJsonPath('data.request_details.outcome','refunded');
  $this->assertEquals($before,\Illuminate\Support\Facades\DB::table('inventory_balances')->sum('quantity_on_hand'));
 }
 public function test_complaint_and_other_groups_validate_required_result_information():void{
  $this->actor();$groups=\App\Support\ServiceRequestWorkflow::groups();$this->assertCount(9,$groups);
  foreach($groups as $key=>$group){try{\App\Support\ServiceRequestWorkflow::validateDetails($key,[],true);$this->fail('Expected closing validation for '.$key);}catch(\Illuminate\Validation\ValidationException $e){$this->assertNotEmpty($e->errors());}}
  $this->expectException(\Illuminate\Validation\ValidationException::class);
  \App\Support\ServiceRequestWorkflow::validateDetails('resort',['arrival_date'=>'2026-11-10','departure_date'=>'2026-11-01']);
 }
 public function test_staff_cannot_change_company_routing():void{
  $actor=$this->actor();$staff=User::where('email','sales@vk-kpi.local')->firstOrFail();$this->withToken(app(\App\Support\JwtTokenService::class)->issue($staff))->putJson('/api/v1/service-desk/routing',['routing'=>[]])->assertForbidden();
 }
}
