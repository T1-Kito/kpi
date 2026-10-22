<?php
namespace Tests\Feature;

use App\Models\{Customer,User,SlaPolicy,Alert};
use App\Support\{ServiceCalendar,ServiceTicketEscalation};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceSlaPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function calendar(): array {return ['clock'=>'business','timezone'=>'Asia/Ho_Chi_Minh','weekdays'=>[1,2,3,4,5],'periods'=>[['start'=>'08:00','end'=>'12:00'],['start'=>'13:00','end'=>'17:00']],'holidays'=>['2026-10-26'],'allow_pause'=>true,'response_minutes'=>60];}

    public function test_calendar_skips_lunch_weekend_holiday_and_handles_timezone(): void
    {
        $rules=$this->calendar();
        $start=CarbonImmutable::parse('2026-10-23 11:30','Asia/Ho_Chi_Minh');
        $this->assertSame('2026-10-23 13:30',ServiceCalendar::addSeconds($start,3600,$rules)->format('Y-m-d H:i'));
        $start=CarbonImmutable::parse('2026-10-23 16:30','Asia/Ho_Chi_Minh');
        $end=ServiceCalendar::addSeconds($start,7200,$rules);
        $this->assertSame('2026-10-27 09:30',$end->format('Y-m-d H:i'));
        $this->assertSame(7200,ServiceCalendar::secondsBetween($start,$end,$rules));
        $this->assertSame($end->getTimestamp(),ServiceCalendar::addSeconds($start->utc(),7200,$rules)->getTimestamp());
    }

    public function test_policy_snapshot_pause_resume_and_escalation_are_audited_repeat_safe(): void
    {
        $this->seed();$this->travelTo(CarbonImmutable::parse('2026-10-23 16:00','Asia/Ho_Chi_Minh')->utc());
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $token=$this->postJson('/api/v1/auth/login',['email'=>$admin->email,'password'=>'Admin@123'])->json('data.access_token');
        $policy=$this->withToken($token)->postJson('/api/v1/sla-policies',['module'=>'service','task_type'=>'ticket','priority'=>'normal','duration_minutes'=>120,'warning_before_minutes'=>15,'escalation_rules'=>$this->calendar()+['recipient_ids'=>[$admin->id]]])->assertCreated()->json('data');
        $customer=Customer::firstOrFail();
        $this->assertSame('2026-10-27 02:00',ServiceCalendar::addSeconds(now(),7200,$this->calendar())->format('Y-m-d H:i'));
        $ticket=$this->withToken($token)->postJson('/api/v1/service-tickets',['customer_id'=>$customer->id,'assignee_id'=>$admin->id,'subject'=>'SLA lịch làm việc','category'=>'complaint','priority'=>'normal'])->assertCreated()->assertJsonPath('data.sla_clock.clock','business')->json('data');
        $url='/api/v1/service-tickets/'.$ticket['id'];
        $this->assertSame('2026-10-27 09:00',CarbonImmutable::parse($ticket['resolution_due_at'])->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i'),json_encode([$ticket['resolution_due_at'],now()->toIso8601String(),\Illuminate\Support\Facades\DB::table('service_tickets')->where('id',$ticket['id'])->value('resolution_due_at')]));
        $this->withToken($token)->postJson($url.'/sla-clock',['pause'=>true,'reason'=>'Chờ khách'])->assertUnprocessable();
        $this->withToken($token)->putJson($url,['status'=>'pending_customer'])->assertOk();
        $this->withToken($token)->postJson($url.'/sla-clock',['pause'=>true,'reason'=>'Chưa phản hồi lần đầu'])->assertUnprocessable();
        $this->withToken($token)->postJson($url.'/worklogs',['visibility'=>'public','content'=>'Đã liên hệ, đề nghị khách cung cấp serial.'])->assertOk();
        $this->withToken($token)->postJson($url.'/sla-clock',['pause'=>true,'reason'=>'Chờ khách bổ sung serial'])->assertOk()->assertJsonPath('data.resolution_sla.state','paused');
        SlaPolicy::findOrFail($policy['id'])->update(['escalation_rules'=>['clock'=>'continuous','allow_pause'=>false]]);
        $this->travelTo(CarbonImmutable::parse('2026-10-27 09:00','Asia/Ho_Chi_Minh')->utc());
        $this->assertSame(0,app(ServiceTicketEscalation::class)->run());
        $this->withToken($token)->getJson('/api/v1/service-tickets?overdue=1')->assertOk()->assertJsonPath('meta.total',0);
        $this->withToken($token)->putJson($url,['status'=>'resolved','resolution_code'=>'OK','resolution_note'=>'Xong'])->assertUnprocessable();
        $resumed=$this->withToken($token)->postJson($url.'/sla-clock',['pause'=>false,'reason'=>'Khách đã cung cấp thông tin'])->assertOk()->assertJsonPath('data.sla_clock.paused',false)->json('data');
        $this->assertSame('2026-10-27 11:00',CarbonImmutable::parse($resumed['resolution_due_at'])->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i'));
        $this->withToken($token)->postJson($url.'/sla-clock',['pause'=>false,'reason'=>'Lặp lại'])->assertUnprocessable();
        $this->travelTo(CarbonImmutable::parse('2026-10-27 11:01','Asia/Ho_Chi_Minh')->utc());
        $this->assertSame(1,app(ServiceTicketEscalation::class)->run());
        $this->assertSame(0,app(ServiceTicketEscalation::class)->run());
        $this->assertSame(1,Alert::where('source_type','ServiceTicket')->where('source_id',$ticket['id'])->count());
        $this->withToken($token)->postJson($url.'/worklogs',['action'=>'sla_paused','visibility'=>'internal','content'=>'Không được giả nhật ký hệ thống'])->assertOk()->assertJsonPath('data.sla_clock.paused',false);
        $this->withToken($token)->postJson($url.'/sla-clock',['pause'=>true,'reason'=>'Không được che quá hạn'])->assertUnprocessable();
        $this->withToken($token)->putJson($url,['status'=>'resolved','resolution_code'=>'FIXED','resolution_note'=>'Đã khắc phục'])->assertOk();
        app(ServiceTicketEscalation::class)->run();
        $this->assertSame(0,Alert::where('source_type','ServiceTicket')->where('source_id',$ticket['id'])->where('status','open')->count());
    }

    public function test_invalid_calendar_and_recipient_are_rejected(): void
    {
        $this->seed();$token=$this->postJson('/api/v1/auth/login',['email'=>'admin@vk-kpi.local','password'=>'Admin@123'])->json('data.access_token');
        $data=['module'=>'service','task_type'=>'ticket','priority'=>'normal','duration_minutes'=>120,'warning_before_minutes'=>15,'escalation_rules'=>$this->calendar()];
        $data['escalation_rules']['periods']=[['start'=>'17:00','end'=>'08:00']];
        $this->withToken($token)->postJson('/api/v1/sla-policies',$data)->assertUnprocessable();
        $data['escalation_rules']=$this->calendar()+['recipient_ids'=>[999999]];
        $this->withToken($token)->postJson('/api/v1/sla-policies',$data)->assertUnprocessable();
    }
}
