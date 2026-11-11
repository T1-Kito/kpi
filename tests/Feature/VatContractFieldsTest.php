<?php
namespace Tests\Feature;
use App\Models\{User,Customer,Sku};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class VatContractFieldsTest extends TestCase {
 use RefreshDatabase;
 public function test_inclusive_vat_survives_edit_without_double_tax_and_contract_type_is_saved():void {
  $this->seed();$user=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->withToken(app(\App\Support\JwtTokenService::class)->issue($user));$customer=Customer::where('tenant_id',$user->tenant_id)->firstOrFail();$sku=Sku::where('tenant_id',$user->tenant_id)->firstOrFail();
  $payload=['customer_id'=>$customer->id,'prices_include_vat'=>true,'discount_percent'=>0,'items'=>[['sku_id'=>$sku->id,'quantity'=>1,'unit_price'=>108000,'vat_rate'=>8]]];
  $quote=$this->postJson('/api/v1/quotations',$payload)->assertCreated()->assertJsonPath('data.prices_include_vat',true)->assertJsonPath('data.total_amount',fn ($value) => (float) $value === 108000.0)->assertJsonPath('data.tax_amount',fn ($value) => (float) $value === 8000.0)->json('data');
  $this->putJson('/api/v1/quotations/'.$quote['id'],$payload)->assertOk()->assertJsonPath('data.total_amount',fn ($value) => (float) $value === 108000.0)->assertJsonPath('data.items.0.list_unit_price',fn ($value) => (float) $value === 108000.0);
  $this->postJson('/api/v1/quotations/'.$quote['id'].'/duplicate')->assertCreated()->assertJsonPath('data.prices_include_vat',true)->assertJsonPath('data.total_amount',fn ($value) => (float) $value === 108000.0);
  $payload['prices_include_vat']=false;$this->putJson('/api/v1/quotations/'.$quote['id'],$payload)->assertOk()->assertJsonPath('data.total_amount',fn ($value) => (float) $value === 116640.0);
  $this->postJson('/api/v1/contracts',['name'=>'Hợp đồng thử','customer_id'=>$customer->id,'contract_type'=>'Dịch vụ'])->assertCreated()->assertJsonPath('data.contract_type','Dịch vụ');
 }
 public function test_missing_phone_message_is_vietnamese():void{$this->assertSame(['Vui lòng nhập số điện thoại.'],validator([],['phone'=>'required'])->errors()->all());}
 public function test_no_sla_gives_no_deadline_but_configured_sla_sets_one():void {
  $this->seed();$user=User::where('email','admin@vk-kpi.local')->firstOrFail();\App\Models\SlaPolicy::where('tenant_id',$user->tenant_id)->delete();$service=app(\App\Support\TaskService::class);
  $task=$service->create($user,['title'=>'Chưa có SLA','module'=>'sales','task_type'=>'lead_follow_up','priority'=>'normal','assignee_id'=>$user->id],false);$this->assertNull($task->due_at);
  \App\Models\SlaPolicy::create(['tenant_id'=>$user->tenant_id,'module'=>'sales','task_type'=>'lead_follow_up','priority'=>'normal','duration_minutes'=>120]);
  $task=$service->create($user,['title'=>'Có SLA','module'=>'sales','task_type'=>'lead_follow_up','priority'=>'normal','assignee_id'=>$user->id],false);$this->assertNotNull($task->due_at);
 }
}
