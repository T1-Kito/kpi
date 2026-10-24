<?php
namespace Tests\Feature;
use App\Models\{Customer,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class CustomerClassificationTest extends TestCase
{
    use RefreshDatabase;
    public function test_classification_is_separate_from_legal_type_and_existing_records_stay_unclassified():void {
        $this->seed();$user=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $this->withToken(app(\App\Support\JwtTokenService::class)->issue($user));
        $old=Customer::firstOrFail();$this->assertNull($old->business_segment);
        $data=['name'=>'Trường thử phân nhóm','customer_type'=>'organization','business_segment'=>'B2B','customer_group'=>'Trường học','customer_audience'=>'THCS / THPT / Đại học'];
        $id=$this->postJson('/api/v1/customers',$data)->assertCreated()->assertJsonPath('data.business_segment','B2B')->json('data.id');
        $this->putJson('/api/v1/customers/'.$id,['name'=>$data['name'],'customer_type'=>'organization'])->assertOk()->assertJsonPath('data.customer_group','Trường học');
        $this->putJson('/api/v1/customers/'.$id,['name'=>$data['name'],'business_segment'=>'INVALID'])->assertUnprocessable();
        $this->putJson('/api/v1/customers/'.$id,['name'=>$data['name'],'business_segment'=>null,'customer_group'=>null,'customer_audience'=>null])->assertOk()->assertJsonPath('data.business_segment',null);
        $this->assertNull($old->fresh()->business_segment);
    }
}
