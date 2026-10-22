<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceDeskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_creation_and_customer_lookup_respect_customer_scope(): void
    {
        $this->seed();
        $sales=\App\Models\User::where('email','sales@vk-kpi.local')->firstOrFail();
        $admin=\App\Models\User::where('email','admin@vk-kpi.local')->firstOrFail();
        $customer=Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'CARE-PRIVATE','name'=>'Khách riêng','sales_owner_id'=>$admin->id,'status'=>'active']);
        $token=$this->loginAs($sales->email);
        $rows=$this->withToken($token)->getJson('/api/v1/service-desk/lookups')->assertOk()->json('data.customers');
        $this->assertNotContains($customer->id,array_column($rows,'id'));
        $this->withToken($token)->postJson('/api/v1/service-tickets',['customer_id'=>$customer->id,'subject'=>'Ngoài phạm vi','category'=>'support','priority'=>'normal'])->assertNotFound();
        $this->assertDatabaseMissing('service_tickets',['subject'=>'Ngoài phạm vi']);
        $customer->update(['sales_owner_id'=>$sales->id]);
        $this->withToken($token)->postJson('/api/v1/service-tickets',['customer_id'=>$customer->id,'subject'=>'Trong phạm vi','category'=>'support','priority'=>'normal'])->assertCreated();
    }

    public function test_custom_deadline_and_completion_preserve_sla_result(): void
    {
        $this->seed(); $token = $this->loginAs('sales@vk-kpi.local');
        $customer = Customer::where('code','CUS-001')->firstOrFail();
        $data = ['customer_id'=>$customer->id,'assignee_id'=>\App\Models\User::where('email','sales@vk-kpi.local')->value('id'),'subject'=>'Giao hàng chậm','category'=>'delivery','related_document'=>'GH-TEST','priority'=>'normal','resolution_due_at'=>now()->addHours(10)->toIso8601String()];
        $created = $this->withToken($token)->postJson('/api/v1/service-tickets',$data)->assertCreated()->assertJsonPath('data.resolution_sla.color','green')->json('data');
        $this->withToken($token)->putJson('/api/v1/service-tickets/'.$created['id'],['status'=>'closed'])->assertUnprocessable();
        $this->assertSame('GH-TEST',$created['related_document']);
        $resolved = $this->withToken($token)->putJson('/api/v1/service-tickets/'.$created['id'],['status'=>'resolved','resolution_code'=>'CUSTOMER_AGREED','resolution_note'=>'Đã liên hệ và hẹn giao lại.'])->assertOk()->assertJsonPath('data.resolution_sla.state','on_time')->json('data.resolved_at');
        $this->travel(20)->hours();
        $this->withToken($token)->putJson('/api/v1/service-tickets/'.$created['id'],['status'=>'resolved','resolution_code'=>'CUSTOMER_AGREED','resolution_note'=>'Giữ kết quả xử lý.'])->assertOk()->assertJsonPath('data.resolved_at',$resolved)->assertJsonPath('data.resolution_sla.state','on_time');
        $this->withToken($token)->putJson('/api/v1/service-tickets/'.$created['id'],['status'=>'closed','resolution_note'=>'Khách đã nhận hàng.'])->assertUnprocessable();
        $this->withToken($token)->putJson('/api/v1/service-tickets/'.$created['id'],['status'=>'closed','customer_confirmation'=>'accepted','resolution_note'=>'Chưa trả lời khách.'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/service-tickets/'.$created['id'].'/worklogs',['visibility'=>'public','content'=>'Đã gọi thông báo kết quả giao lại cho khách.'])->assertOk();
        $this->withToken($token)->putJson('/api/v1/service-tickets/'.$created['id'],['status'=>'closed','customer_confirmation'=>'accepted','resolution_note'=>'Khách đã nhận hàng.'])->assertOk()->assertJsonPath('data.customer_confirmation','accepted');
        $this->withToken($token)->postJson('/api/v1/service-tickets',array_replace($data,['resolution_due_at'=>now()->subHour()->toIso8601String()]))->assertUnprocessable();
    }

    public function test_sales_can_create_ticket_and_warranty_claim_by_serial(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $customer = Customer::where('code', 'CUS-001')->firstOrFail();

        $ticket = $this->withToken($token)->postJson('/api/v1/service-tickets', [
            'customer_id' => $customer->id,
            'serial_number' => 'SER-SVC-001',
            'product_name' => 'Máy in tại quầy lễ tân',
            'subject' => 'Máy in không in được tem',
            'category' => 'warranty',
            'priority' => 'high',
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson('/api/v1/warranty-claims', [
            'customer_id' => $customer->id,
            'serial_numbers' => ['SER-SVC-001', 'SER-SVC-002'],
            'product_name' => 'Máy in tại quầy lễ tân',
            'customer_name' => $customer->name,
            'warranty_start_at' => now()->toDateString(),
            'warranty_months' => 12,
        ])->assertCreated()->assertJsonPath('meta.created', 2)->assertJsonPath('data.0.warranty_status', 'in_warranty');
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Admin@123'])->json('data.access_token');
    }
    public function test_customer_rejection_resumes_original_sla_and_requires_latest_response():void
    {
        $this->seed();$this->freezeTime();$token=$this->loginAs('sales@vk-kpi.local');
        $user=\App\Models\User::where('email','sales@vk-kpi.local')->firstOrFail();$customer=Customer::where('code','CUS-001')->firstOrFail();
        $ticket=$this->withToken($token)->postJson('/api/v1/service-tickets',['customer_id'=>$customer->id,'assignee_id'=>$user->id,'subject'=>'Giao thiếu hàng','category'=>'delivery','priority'=>'urgent'])->assertCreated()->json('data');
        $path='/api/v1/service-tickets/'.$ticket['id'];
        $this->withToken($token)->putJson($path,['status'=>'in_progress'])->assertOk();
        $this->withToken($token)->putJson($path,['status'=>'resolved','resolution_code'=>'DELIVER_AGAIN','resolution_note'=>'Đã giao bù.'])->assertOk();
        $this->withToken($token)->postJson($path.'/worklogs',['visibility'=>'public','content'=>'Đã gọi khách lần 1.'])->assertOk();
        $this->withToken($token)->putJson($path,['status'=>'in_progress','resolution_note'=>'Vẫn thiếu hàng.'])->assertUnprocessable();
        $this->withToken($token)->putJson($path,['status'=>'in_progress','customer_confirmation'=>'rejected','resolution_note'=>'Khách báo giao bù vẫn thiếu.'])->assertOk()->assertJsonPath('data.resolved_at',null)->assertJsonPath('data.resolution_due_at',$ticket['resolution_due_at']);
        $this->travel(5)->hours();
        $this->withToken($token)->getJson($path)->assertOk()->assertJsonPath('data.resolution_sla.color','red');
        $this->withToken($token)->putJson($path,['status'=>'resolved','resolution_code'=>'DELIVER_COMPLETE','resolution_note'=>'Giao đủ lần 2.'])->assertOk()->assertJsonPath('data.resolution_sla.state','late');
        $close=['status'=>'closed','customer_confirmation'=>'accepted','resolution_note'=>'Khách đồng ý.'];
        $this->withToken($token)->putJson($path,$close)->assertUnprocessable();
        $this->withToken($token)->postJson($path.'/worklogs',['visibility'=>'public','content'=>'Đã gọi xác nhận đủ hàng lần 2.'])->assertOk();
        $this->withToken($token)->putJson($path,$close)->assertOk()->assertJsonPath('data.customer_confirmation','accepted');
        $this->assertDatabaseHas('ticket_worklogs',['service_ticket_id'=>$ticket['id'],'action'=>'customer_rejected']);
        $this->withToken($token)->putJson($path,['status'=>'in_progress','customer_confirmation'=>'rejected','resolution_note'=>'Không được sửa hồ sơ đã đóng.'])->assertUnprocessable();
    }
    public function test_ticket_search_overdue_assignee_and_paging():void
    {
        $this->seed();$token=$this->loginAs('sales@vk-kpi.local');$user=\App\Models\User::where('email','sales@vk-kpi.local')->firstOrFail();$customer=Customer::where('code','CUS-001')->firstOrFail();
        foreach(['Giao thiếu hàng A','Giao thiếu hàng B'] as $subject)$this->withToken($token)->postJson('/api/v1/service-tickets',['customer_id'=>$customer->id,'assignee_id'=>$user->id,'subject'=>$subject,'category'=>'delivery','priority'=>'urgent'])->assertCreated();
        $this->travel(5)->hours();
        $result=$this->withToken($token)->getJson('/api/v1/service-tickets?overdue=1&assignee_id='.$user->id.'&page_size=1&search='.urlencode('Giao thiếu'))->assertOk()->assertJsonPath('meta.total',2)->assertJsonCount(1,'data')->json('data.0.id');
        $next=$this->withToken($token)->getJson('/api/v1/service-tickets?overdue=1&page_size=1&page=2')->assertOk()->json('data.0.id');$this->assertNotSame($result,$next);
        $this->withToken($token)->getJson('/api/v1/service-tickets?overdue=1&status=closed')->assertOk()->assertJsonPath('meta.total',0);
        $this->withToken($token)->getJson('/api/v1/service-tickets?page_size=-1')->assertUnprocessable();
    }
    public function test_feedback_documents_are_checked_against_customer_and_permissions(): void
    {
        $this->seed(); $token=$this->loginAs('admin@vk-kpi.local');
        $actor=\App\Models\User::where('email','admin@vk-kpi.local')->firstOrFail();
        $customer=Customer::where('tenant_id',$actor->tenant_id)->firstOrFail();
        $other=Customer::create(['tenant_id'=>$actor->tenant_id,'code'=>'CUS-FEEDBACK-OTHER','name'=>'Other customer','status'=>'active']);
        $quote=\App\Models\Quotation::create(['tenant_id'=>$actor->tenant_id,'customer_id'=>$customer->id,'sales_owner_id'=>$actor->id,'code'=>'Q-FEEDBACK-TEST','status'=>'draft','subtotal_amount'=>0,'tax_amount'=>0,'total_amount'=>0]);
        $this->withToken($token)->getJson('/api/v1/service-desk/documents?type=quotation&customer_id='.$customer->id)->assertOk()->assertJsonFragment(['code'=>$quote->code]);
        $payload=['customer_id'=>$customer->id,'subject'=>'Giá chưa phù hợp','category'=>'quotation','priority'=>'normal','document_type'=>'quotation','document_id'=>$quote->id,'related_document'=>'FAKE'];
        $this->withToken($token)->postJson('/api/v1/service-tickets',$payload)->assertCreated()->assertJsonPath('data.related_document',$quote->code)->assertJsonPath('data.document_id',$quote->id);
        $this->withToken($token)->postJson('/api/v1/service-tickets',array_replace($payload,['customer_id'=>$other->id]))->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/service-tickets',array_replace($payload,['document_id'=>null]))->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/service-desk/documents?type=quotation&customer_id=99999999')->assertNotFound();
        $this->assertDatabaseCount('service_tickets',1);
    }
}
