<?php
namespace Tests\Feature;
use App\Models\{User,AuditLog};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class InteractionLogTest extends TestCase
{
    use RefreshDatabase;
    public function test_browser_events_are_bounded_authenticated_and_do_not_store_input_values():void {
        $this->seed();
        $events=[['kind'=>'click','path'=>'/leads','control'=>'lead-edit','password'=>'do-not-record','value'=>'private customer text','user_id'=>999]];
        $this->postJson('/api/v1/interaction-logs',['events'=>$events])->assertUnauthorized();
        $user=User::where('email','sales@vk-kpi.local')->firstOrFail();$this->actingToken($user);
        $this->postJson('/api/v1/interaction-logs',['events'=>$events])->assertCreated()->assertJsonPath('recorded',1);
        $log=AuditLog::where('action','ui_click')->firstOrFail();
        $this->assertSame($user->id,$log->user_id);$this->assertSame($user->tenant_id,$log->tenant_id);
        $this->assertSame('/leads',$log->new_value['path']);$this->assertSame('lead-edit',$log->new_value['control']);
        $this->assertArrayNotHasKey('password',$log->new_value);$this->assertArrayNotHasKey('value',$log->new_value);$this->assertNotEmpty($log->new_value['observed_at']);
        $this->postJson('/api/v1/interaction-logs',['events'=>[['kind'=>'click','path'=>'/leads?email=secret']]])->assertUnprocessable();
        $this->postJson('/api/v1/interaction-logs',['events'=>array_fill(0,21,$events[0])])->assertUnprocessable();
        $this->getJson('/api/v1/audit-logs/'.$log->id)->assertForbidden();
    }
    public function test_timeline_is_chronological_bounded_and_scoped_to_same_actor_tenant():void {
        $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();$sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $this->actingToken($admin);
        $first=AuditLog::create(['tenant_id'=>$admin->tenant_id,'user_id'=>$admin->id,'action'=>'ui_click','entity_type'=>'ui_activity','new_value'=>['path'=>'/leads','control'=>'lead-edit']]);
        AuditLog::create(['tenant_id'=>$admin->tenant_id,'user_id'=>$sales->id,'action'=>'ui_click','entity_type'=>'ui_activity']);
        $last=AuditLog::create(['tenant_id'=>$admin->tenant_id,'user_id'=>$admin->id,'action'=>'update_lead','entity_type'=>'lead','entity_id'=>1]);
        $rows=$this->getJson('/api/v1/audit-logs/'.$last->id)->assertOk()->json('data.steps');
        $this->assertContains($first->id,array_column($rows,'id'));$this->assertSame($last->id,end($rows)['id']);
        foreach($rows as $row)$this->assertNotSame($sales->id,AuditLog::find($row['id'])->user_id);
    }
    private function actingToken(User $user):void { $this->withToken(app(\App\Support\JwtTokenService::class)->issue($user)); }
}
