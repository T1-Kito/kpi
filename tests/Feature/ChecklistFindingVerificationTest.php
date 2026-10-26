<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{User,Customer,Deal,Sku,Contract,Quotation,SalesOrder};

/** Regression tests for checklist findings F01–F06. */
class ChecklistFindingVerificationTest extends TestCase {
    use RefreshDatabase;
    private function token(): string {
        $this->seed();
        return $this->postJson('/api/v1/auth/login',['email'=>'sales@vk-kpi.local','password'=>'Admin@123'])->assertOk()->json('data.access_token');
    }
    public function test_f01_out_of_scope_and_terminal_item_mutation_is_blocked(): void {
        $token=$this->token();$other=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $deal=Deal::create(['tenant_id'=>$other->tenant_id,'code'=>'VERIFY-F01','name'=>'Other owner','owner_id'=>$other->id,'stage'=>'new','status'=>'open','version'=>1,'amount'=>0,'probability'=>10]);
        $this->withToken($token)->getJson("/api/v1/deals/$deal->id")->assertNotFound();
        $body=['version'=>1,'items'=>[['sku_id'=>Sku::first()->id,'quantity'=>2,'unit_price'=>100]]];
        $this->withToken($token)->putJson("/api/v1/deals/$deal->id/items",$body)->assertNotFound();
        $this->assertEquals(0,$deal->fresh()->amount);
        $deal->update(['owner_id'=>User::where('email','sales@vk-kpi.local')->value('id'),'stage'=>'won','status'=>'won']);
        $this->withToken($token)->putJson("/api/v1/deals/$deal->id/items",$body)->assertConflict();
        $this->assertEquals(0,$deal->fresh()->amount);
        $deal->update(['status'=>'open','stage'=>'new']);
        $this->withToken($token)->putJson("/api/v1/deals/$deal->id/items",$body)->assertOk();
        $this->assertEquals(200,$deal->fresh()->amount);
        $this->withToken($token)->putJson("/api/v1/deals/$deal->id/items",$body)->assertConflict();
        $this->assertEquals(200,$deal->fresh()->amount);
    }
    public function test_f02_contract_actions_enforce_owner_scope(): void {
        $token=$this->token();$other=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $c=Contract::create(['tenant_id'=>$other->tenant_id,'code'=>'VERIFY-F02','name'=>'Other contract','customer_id'=>Customer::first()->id,'owner_id'=>$other->id,'total_amount'=>1000,'status'=>'draft']);
        $m=$c->milestones()->create(['name'=>'Payment','amount'=>1000,'status'=>'pending']);
        $this->withToken($token)->getJson("/api/v1/contracts/$c->id")->assertNotFound();
        $this->withToken($token)->postJson("/api/v1/contracts/$c->id/activate")->assertNotFound();
        $c->update(['status'=>'active']);
        $this->withToken($token)->postJson("/api/v1/contracts/$c->id/milestones/$m->id/accept")->assertNotFound();
        $this->assertEquals('pending',$m->fresh()->status);
    }
    public function test_f03_won_requires_accepted_quote_for_same_customer_and_deal(): void {
        $token=$this->token();
        $deal=$this->withToken($token)->postJson('/api/v1/deals',['name'=>'Verify won','customer_id'=>Customer::first()->id,'amount'=>1000,'expected_close_date'=>now()->addDay()->toDateString()])->assertCreated()->json('data');
        $this->assertSame(0,Quotation::where('deal_id',$deal['id'])->count());
        $url='/api/v1/deals/'.$deal['id'].'/transition';
        $body=['stage'=>'won','version'=>$deal['version']];
        $this->withToken($token)->postJson($url,$body)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/deals',['name'=>'Bypass','stage'=>'won'])->assertUnprocessable();
        $quote=Quotation::firstOrFail()->replicate();
        $quote->code='VERIFY-ACCEPTED'; $quote->deal_id=$deal['id']; $quote->customer_id=$deal['customer_id']; $quote->status='approved'; $quote->accepted_at=null; $quote->save();
        $this->withToken($token)->postJson($url,$body)->assertUnprocessable();
        $quote->update(['status'=>'accepted','accepted_at'=>now()]);
        $this->withToken($token)->postJson($url,$body)->assertOk()->assertJsonPath('data.status','won');
    }
    public function test_f04_cost_is_hidden_until_separate_permission_is_granted(): void {
        $token=$this->token();$sku=Sku::firstOrFail();
        $this->withToken($token)->getJson('/api/v1/skus')->assertOk()->assertJsonMissingPath('data.0.cost_price');
        $this->withToken($token)->getJson('/api/v1/skus/'.$sku->id)->assertOk()->assertJsonMissingPath('data.cost_price');
        $this->withToken($token)->getJson('/api/v1/quotations')->assertOk()->assertJsonMissingPath('data.0.total_cost')->assertJsonMissingPath('data.0.margin_percent');
        $quote=Quotation::where('sales_owner_id',User::where('email','sales@vk-kpi.local')->value('id'))->firstOrFail();
        $this->withToken($token)->getJson('/api/v1/quotations/'.$quote->id)->assertOk()->assertJsonMissingPath('data.items.0.unit_cost')->assertJsonMissingPath('data.total_cost');
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $sales->roles()->first()->permissions()->syncWithoutDetaching([\App\Models\Permission::where('code','master.cost.view')->value('id')]);
        $this->withToken($token)->getJson('/api/v1/skus/'.$sku->id)->assertOk()->assertJsonStructure(['data'=>['cost_price']]);
    }
    public function test_f05_lead_same_key_replays_and_changed_body_conflicts(): void {
        $token=$this->token();$payload=['name'=>'Verify replay','phone'=>'0909876543','source'=>'manual'];
        $a=$this->withToken($token)->withHeader('Idempotency-Key','verify-f05-key')->postJson('/api/v1/leads',$payload)->assertCreated()->json('data.id');
        $b=$this->withToken($token)->withHeader('Idempotency-Key','verify-f05-key')->postJson('/api/v1/leads',$payload)->assertCreated()->json('data.id');
        $this->assertEquals($a,$b);$this->assertDatabaseCount('leads',2);
        $payload['phone']='0909876544';
        $this->withToken($token)->withHeader('Idempotency-Key','verify-f05-key')->postJson('/api/v1/leads',$payload)->assertConflict();
    }
    public function test_f06_mismatched_contract_sources_and_milestone_totals_are_rejected(): void {
        $token=$this->token();$order=SalesOrder::firstOrFail();$quote=Quotation::where('customer_id','!=',$order->customer_id)->firstOrFail();
        $c=Customer::create(['tenant_id'=>$order->tenant_id,'code'=>'VERIFY-C3','name'=>'Third customer','status'=>'active','created_by'=>User::where('email','sales@vk-kpi.local')->value('id')]);
        $this->withToken($token)->postJson('/api/v1/contracts',['name'=>'Conflicting sources','customer_id'=>$c->id,'sales_order_id'=>$order->id,'quotation_id'=>$quote->id])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/contracts',['name'=>'Wrong total','sales_order_id'=>$order->id,'milestones'=>[['name'=>'Wrong amount','amount'=>1]]])->assertUnprocessable();
        $this->assertDatabaseMissing('contracts',['name'=>'Conflicting sources']);
    }
}
