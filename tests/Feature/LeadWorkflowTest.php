<?php
namespace Tests\Feature;

use App\Models\{Lead,User,Task};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private string $admin;
    private string $sales;
    private User $recipient;
    protected function setUp():void {
        parent::setUp();$this->seed();
        $this->admin=$this->token('admin@vk-kpi.local');
        $this->sales=$this->token('sales@vk-kpi.local');
        $this->recipient=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $tenant=$this->recipient->tenant;$settings=$tenant->ui_settings ?? [];
        $settings['lead_handover']=['role_ids'=>$this->recipient->roles()->pluck('roles.id')->all()];$tenant->update(['ui_settings'=>$settings]);
    }
    private function token(string $email):string {
        return $this->postJson('/api/v1/auth/login',['email'=>$email,'password'=>'Admin@123'])->assertOk()->json('data.access_token');
    }
    private function lead(array $extra=[]):array {
        return $this->withToken($this->admin)->postJson('/api/v1/leads',array_replace([
            'name'=>'Đoàn Trường học','phone'=>'0908222111','needs'=>'Tham quan cho học sinh','region'=>'Đồng Nai',
            'expected_service_date'=>'2026-12-01','guest_count'=>100,'interested_services'=>'Vé công viên','notes'=>'Có xe đưa đón',
            'assigned_to'=>User::where('email','admin@vk-kpi.local')->value('id'),
        ],$extra))->assertCreated()->json('data');
    }
    public function test_handover_pool_is_configured_by_admin_and_enforced_on_submission():void {
        $this->withToken($this->sales)->postJson('/api/v1/tenant/lead-handover',['role_ids'=>[]])->assertForbidden();
        $this->withToken($this->admin)->getJson('/api/v1/leads/handover-candidates')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$this->recipient->id);
        $this->withToken($this->admin)->postJson('/api/v1/tenant/lead-handover',['role_ids'=>[]])->assertOk();
        $this->withToken($this->admin)->getJson('/api/v1/leads/handover-candidates')->assertOk()->assertJsonCount(0,'data')->assertJsonPath('meta.configured',false);
        $lead=$this->lead();
        $this->withToken($this->admin)->postJson('/api/v1/leads/'.$lead['id'].'/handover',['recipient_id'=>$this->recipient->id,'note'=>'Không được bỏ qua cấu hình'])->assertUnprocessable();
        $this->assertDatabaseHas('leads',['id'=>$lead['id'],'handover_status'=>null]);
    }
    public function test_handover_requires_acceptance_notifies_and_transfers_only_on_accept():void {
        $lead=$this->lead();$url='/api/v1/leads/'.$lead['id'];
        $this->withToken($this->sales)->getJson($url)->assertNotFound();
        $this->withToken($this->admin)->postJson($url.'/handover',['recipient_id'=>$this->recipient->id,'note'=>'Nhờ tư vấn đoàn'])->assertOk()->assertJsonPath('data.handover_status','pending')->assertJsonPath('data.assigned_to',$lead['assigned_to']);
        $this->assertDatabaseHas('notifications',['recipient_id'=>$this->recipient->id,'source_id'=>$lead['id'],'title'=>'Lead chờ bạn tiếp nhận']);
        $this->withToken($this->sales)->getJson($url)->assertOk()->assertJsonPath('data.can_write',false);
        $this->withToken($this->sales)->putJson($url,['name'=>'Không được sửa','phone'=>$lead['phone']])->assertNotFound();
        $this->withToken($this->admin)->postJson($url.'/assign',['assigned_to'=>$this->recipient->id])->assertStatus(409);
        $this->withToken($this->admin)->postJson($url.'/qualify',[])->assertStatus(409);
        $this->withToken($this->admin)->postJson($url.'/handover-response',['decision'=>'accepted','note'=>'Nhận hộ'])->assertStatus(409);
        $this->withToken($this->sales)->postJson($url.'/handover-response',['decision'=>'accepted','note'=>'Đã kiểm tra đủ thông tin'])->assertOk()->assertJsonPath('data.assigned_to',$this->recipient->id);
        $this->withToken($this->sales)->postJson($url.'/handover-response',['decision'=>'accepted','note'=>'Lặp lại'])->assertStatus(409);
        $this->assertSame(1,Task::where('source_type','Lead')->where('source_id',$lead['id'])->where('status','new')->where('assignee_id',$this->recipient->id)->count());
        $result=$this->withToken($this->sales)->postJson($url.'/qualify',[])->assertOk();
        $result->assertJsonPath('data.deal.needs.guest_count',100)->assertJsonPath('data.deal.needs.service_date','2026-12-01')->assertJsonPath('data.deal.needs.purpose','Tham quan cho học sinh');
        $sku=\App\Models\Sku::where('tenant_id',$this->recipient->tenant_id)->where('status','active')->firstOrFail();
        $this->withToken($this->sales)->postJson('/api/v1/quotations',['deal_id'=>$result->json('data.deal.id'),'items'=>[['sku_id'=>$sku->id,'quantity'=>2,'unit_price'=>100000]]])->assertCreated()->assertJsonPath('data.customer_id',$result->json('data.customer.id'))->assertJsonPath('data.sales_owner_id',$this->recipient->id);
    }
    public function test_return_keeps_original_owner_and_requires_information():void {
        $lead=$this->lead(['needs'=>null]);$url='/api/v1/leads/'.$lead['id'];
        $this->withToken($this->admin)->postJson($url.'/handover',['recipient_id'=>$this->recipient->id,'note'=>'Gửi đoàn'])->assertStatus(422);
        $this->withToken($this->admin)->putJson($url,['name'=>$lead['name'],'phone'=>$lead['phone'],'needs'=>'Tham quan'])->assertOk();
        $this->withToken($this->admin)->postJson($url.'/handover',['recipient_id'=>$this->recipient->id,'note'=>'Gửi đoàn'])->assertOk();
        $this->withToken($this->sales)->postJson($url.'/handover-response',['decision'=>'returned','note'=>'Cần xác nhận thêm lịch'])->assertOk()->assertJsonPath('data.assigned_to',$lead['assigned_to'])->assertJsonPath('data.handover_status','returned');
    }
    public function test_accentless_search_source_campaign_and_literal_wildcards():void {
        $lead=$this->lead(['source'=>'Giới thiệu','campaign_code'=>'Mùa hè_100%']);
        foreach(['doan truong hoc','dong nai','gioi thieu','mua he_100%'] as $term) $this->withToken($this->admin)->getJson('/api/v1/leads?q='.urlencode($term))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$lead['id']);
        $this->withToken($this->admin)->getJson('/api/v1/leads?q='.urlencode('[khong co]'))->assertOk()->assertJsonCount(0,'data');
        $this->withToken($this->sales)->getJson('/api/v1/leads?q=doan')->assertOk()->assertJsonCount(0,'data');
    }
    public function test_phone_search_normalizes_international_local_and_old_formatted_rows_without_leaking():void {
        $lead=$this->lead(['phone'=>'0912360002']);
        foreach(['0912360002','+84 912 360 002','0912-360-002','84912360002'] as $q) $this->withToken($this->admin)->getJson('/api/v1/leads?q='.urlencode($q))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$lead['id']);
        \Illuminate\Support\Facades\DB::table('leads')->where('id',$lead['id'])->update(['phone'=>'+84 (912) 360-002','search_text'=>'old index']);
        $this->withToken($this->admin)->getJson('/api/v1/leads?q=0912360002')->assertOk()->assertJsonCount(1,'data');
        $this->withToken($this->sales)->getJson('/api/v1/leads?q=0912360002')->assertOk()->assertJsonCount(0,'data');
    }
    public function test_changes_during_handover_keep_description_and_notify_with_named_audit():void {
        $lead=$this->lead();$url='/api/v1/leads/'.$lead['id'];
        $this->withToken($this->admin)->postJson($url.'/handover',['recipient_id'=>$this->recipient->id,'note'=>'Nhờ tư vấn đoàn'])->assertOk();
        $this->withToken($this->admin)->putJson($url,['name'=>$lead['name'],'phone'=>$lead['phone'],'needs'=>$lead['needs'],'guest_count'=>65,'expected_service_date'=>'2026-12-29'])->assertOk()->assertJsonPath('data.needs',$lead['needs']);
        $this->withToken($this->sales)->getJson($url)->assertOk()->assertJsonPath('data.guest_count',65)->assertJsonPath('data.expected_service_date','2026-12-29')->assertJsonPath('data.assigned_to',$lead['assigned_to']);
        $this->assertDatabaseHas('notifications',['recipient_id'=>$this->recipient->id,'source_id'=>$lead['id'],'title'=>'Thông tin bàn giao được cập nhật']);
        $log=\App\Models\AuditLog::where('action','handover_lead')->where('entity_id',$lead['id'])->firstOrFail();
        $this->withToken($this->admin)->getJson('/api/v1/audit-logs/'.$log->id)->assertOk()->assertJsonPath('data.reference_users.'.$this->recipient->id,$this->recipient->name);
    }
    public function test_merge_preserves_history_tasks_and_existing_target_values():void {
        $source=$this->lead();$target=$this->lead(['name'=>'Hồ sơ giữ lại','needs'=>'Nhu cầu giữ lại','region'=>null,'allow_duplicate'=>true]);
        $task=Task::where('source_type','Lead')->where('source_id',$source['id'])->firstOrFail();
        $this->withToken($this->admin)->postJson('/api/v1/leads/'.$source['id'].'/merge',['target_id'=>$target['id'],'reason'=>'Đã gọi xác nhận cùng một khách'])->assertOk()->assertJsonPath('data.needs','Nhu cầu giữ lại')->assertJsonPath('data.region','Đồng Nai');
        $this->assertDatabaseHas('leads',['id'=>$source['id'],'merged_into_id'=>$target['id']]);
        $this->assertDatabaseHas('tasks',['id'=>$task->id,'source_id'=>$target['id']]);
        $this->assertSame($source['code'],Lead::findOrFail($target['id'])->workflow_history[0]['details']['source_code']);
        $this->withToken($this->admin)->getJson('/api/v1/leads?q='.urlencode('0908222111'))->assertJsonCount(1,'data');
        $this->withToken($this->admin)->getJson('/api/v1/leads/'.$source['id'])->assertOk()->assertJsonPath('data.id',$target['id']);
        $this->withToken($this->admin)->postJson('/api/v1/leads/'.$source['id'].'/merge',['target_id'=>$target['id'],'reason'=>'Không gộp lần hai'])->assertStatus(409);
    }
    public function test_merge_cannot_access_other_owners_or_converted_records():void {
        $source=$this->lead();$target=$this->lead(['allow_duplicate'=>true,'assigned_to'=>$this->recipient->id]);
        $this->withToken($this->sales)->postJson('/api/v1/leads/'.$target['id'].'/merge',['target_id'=>$source['id'],'reason'=>'Không được phép gộp'])->assertNotFound();
        Lead::findOrFail($source['id'])->update(['status'=>'qualified']);
        $this->withToken($this->admin)->postJson('/api/v1/leads/'.$source['id'].'/merge',['target_id'=>$target['id'],'reason'=>'Không được gộp chứng từ'])->assertStatus(409);
    }
    public function test_duplicate_confirmation_does_not_consume_idempotency_key_and_edit_checks_duplicates():void {
        $lead=$this->lead();
        $data=['name'=>'Nhu cầu riêng','phone'=>'+84 908222111','assigned_to'=>$lead['assigned_to']];
        $this->withToken($this->admin)->withHeader('Idempotency-Key','duplicate-key')->postJson('/api/v1/leads',$data)->assertStatus(409);
        $this->assertDatabaseMissing('request_replays',['request_key'=>'duplicate-key']);
        $data['allow_duplicate']=true;
        $second=$this->withToken($this->admin)->postJson('/api/v1/leads',$data)->assertCreated()->json('data');
        $this->withToken($this->admin)->postJson('/api/v1/leads',$data)->assertCreated()->assertJsonPath('data.id',$second['id']);
        $this->withToken($this->admin)->putJson('/api/v1/leads/'.$second['id'],['name'=>'Sửa trùng','phone'=>$lead['phone']])->assertStatus(409);
        $this->assertDatabaseMissing('leads',['name'=>'Sửa trùng']);
    }
}
