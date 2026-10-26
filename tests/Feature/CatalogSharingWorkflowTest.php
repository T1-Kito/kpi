<?php
namespace Tests\Feature;

use App\Models\{Customer,User};
use App\Support\{JwtTokenService,LeadService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSharingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_created_customer_captures_creator_even_without_http_middleware():void
    {
        $this->seed();$sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $service=app(LeadService::class);
        $lead=$service->create($sales,['name'=>'Service creator','phone'=>'0908777001']);
        $customer=$service->qualify($lead,$sales,[])['customer'];
        $this->assertSame($sales->id,$customer->created_by);
        $this->withToken(app(JwtTokenService::class)->issue($sales))->getJson('/api/v1/customers/'.$customer->id)->assertOk();
    }

    public function test_hidden_customer_blocks_duplicate_lead_conversion_without_exposing_details():void
    {
        $this->seed();$sales=User::where('email','sales@vk-kpi.local')->firstOrFail();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $hidden=Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'HIDDEN-QUALIFY','name'=>'Secret company name','phone'=>'0912 666 555','customer_type'=>'organization','status'=>'active','created_by'=>$admin->id,'sales_owner_id'=>$sales->id]);
        $this->withToken(app(JwtTokenService::class)->issue($sales));
        $leadData=$this->postJson('/api/v1/leads',['name'=>'Interested contact','phone'=>'+84 912666555'])->assertCreated()->json('data');
        $lead=$leadData['id'];
        $before=Customer::count();
        $this->postJson('/api/v1/leads/'.$lead.'/qualify',[])->assertUnprocessable()->assertDontSee('Secret company name')->assertJsonMissingPath('data');
        $this->assertSame($before,Customer::count());
        $this->assertDatabaseMissing('deals',['lead_id'=>$lead]);
        $this->assertDatabaseHas('leads',['id'=>$lead,'status'=>$leadData['status']]);
        $this->getJson('/api/v1/customers/'.$hidden->id)->assertNotFound();
    }
}
