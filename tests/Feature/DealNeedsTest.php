<?php
namespace Tests\Feature;
use App\Models\{User,Deal};
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class DealNeedsTest extends TestCase
{
    use RefreshDatabase;
    public function test_each_opportunity_keeps_its_own_needs_and_no_schedule_creates_no_task():void
    {
        $this->seed();$sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $this->withToken(app(JwtTokenService::class)->issue($sales));
        $customer=\App\Models\Customer::where('is_shared',true)->firstOrFail();
        $a=$this->postJson('/api/v1/deals',['name'=>'Chuyến A','customer_id'=>$customer->id,'needs'=>['guest_count'=>10]])->assertCreated()->json('data.id');
        $b=$this->postJson('/api/v1/deals',['name'=>'Chuyến B','customer_id'=>$customer->id,'needs'=>['guest_count'=>25]])->assertCreated()->json('data.id');
        $this->assertNotEquals($a,$b);
        $this->getJson('/api/v1/deals/'.$a)->assertOk()->assertJsonPath('data.needs.guest_count',10);
        $this->getJson('/api/v1/deals/'.$b)->assertOk()->assertJsonPath('data.needs.guest_count',25);
        $this->assertSame(0,\App\Models\Task::where('source_type','Deal')->whereIn('source_id',[$a,$b])->count());
        $this->getJson('/api/v1/lookups/deal-users')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$sales->id);
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $this->postJson('/api/v1/deals',['name'=>'Sai phân công','owner_id'=>$admin->id])->assertForbidden();
        $this->postJson('/api/v1/deals',['name'=>'Ngày hẹn cũ','next_activity_at'=>now()->subDay()->toDateTimeString()])->assertUnprocessable();
    }
    public function test_needs_create_edit_scope_validation_version_and_terminal_guard():void
    {
        $this->seed();$sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $this->withToken(app(JwtTokenService::class)->issue($sales));
        $needs=['purpose'=>'Ngoại khóa','guest_count'=>180,'service_date'=>'2027-03-20','budget'=>90000000,'interests'=>['Vé','Suất ăn'],'special_requests'=>'Xe đưa đón'];
        $row=$this->postJson('/api/v1/deals',['name'=>'Dã ngoại','needs'=>$needs,'next_activity_at'=>now()->addDays(2)->toIso8601String(),'expected_close_date'=>'2027-02-01'])->assertCreated()->assertJsonPath('data.needs.guest_count',180)->json('data');
        $this->assertSame(1,\App\Models\Task::where('source_type','Deal')->where('source_id',$row['id'])->count());
        $url='/api/v1/deals/'.$row['id'].'/needs';
        $this->getJson('/api/v1/deals/'.$row['id'])->assertOk()->assertJsonPath('data.needs.service_date','2027-03-20');
        $this->putJson($url,['version'=>$row['version'],'needs'=>['guest_count'=>-1]])->assertUnprocessable();
        $this->putJson($url,['version'=>$row['version'],'needs'=>['interests'=>['Vé','Vé']]])->assertUnprocessable();
        $this->putJson($url,['version'=>$row['version'],'needs'=>['unsafe'=>'bad']])->assertUnprocessable();
        $needs['guest_count']=200;
        $this->putJson($url,['version'=>$row['version'],'needs'=>$needs])->assertOk()->assertJsonPath('data.needs.guest_count',200);
        $this->putJson($url,['version'=>$row['version'],'needs'=>$needs])->assertConflict();
        $this->assertDatabaseHas('deals',['id'=>$row['id'],'expected_close_date'=>'2027-02-01 00:00:00']);
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        Deal::whereKey($row['id'])->update(['owner_id'=>$admin->id]);
        $this->putJson($url,['version'=>2,'needs'=>$needs])->assertNotFound();
        Deal::whereKey($row['id'])->update(['owner_id'=>$sales->id,'status'=>'lost']);
        $this->putJson($url,['version'=>2,'needs'=>$needs])->assertConflict();
    }
}
