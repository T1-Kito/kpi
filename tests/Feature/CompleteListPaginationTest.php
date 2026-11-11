<?php
namespace Tests\Feature;
use App\Models\{User,AuditLog,PurchaseRequest};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class CompleteListPaginationTest extends TestCase {
 use RefreshDatabase;
 public function test_old_audit_logs_and_full_sequence_are_accessible():void {
  $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->withToken(app(\App\Support\JwtTokenService::class)->issue($admin));
  $first=null;$last=null;
  for($i=0;$i<125;$i++){$row=AuditLog::create(['tenant_id'=>$admin->tenant_id,'user_id'=>$admin->id,'entity_type'=>'lead','entity_id'=>$i+1,'action'=>'update_lead','new_value'=>['name'=>$i===0?'OLD UNIQUE AUDIT':'Other '.$i]]);$first??=$row;$last=$row;}
  $this->getJson('/api/v1/audit-logs?q=OLD%20UNIQUE%20AUDIT')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$first->id);
  $this->getJson('/api/v1/audit-logs?page=5&page_size=30')->assertOk()->assertJsonFragment(['id'=>$first->id]);
  $detail=$this->getJson('/api/v1/audit-logs/'.$last->id.'?steps_page=3')->assertOk();$this->assertGreaterThanOrEqual(125,$detail->json('data.steps_meta.total'));$detail->assertJsonFragment(['id'=>$last->id]);
  $this->withToken(app(\App\Support\JwtTokenService::class)->issue(User::where('email','sales@vk-kpi.local')->firstOrFail()))->getJson('/api/v1/audit-logs')->assertForbidden();
 }
 public function test_approval_search_is_not_limited_to_fifty_records():void {
  $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->withToken(app(\App\Support\JwtTokenService::class)->issue($admin));
  for($i=0;$i<65;$i++)PurchaseRequest::create(['tenant_id'=>$admin->tenant_id,'code'=>'PAGING-PR-'.$i,'requester_id'=>$admin->id,'reason'=>$i===0?'OLD UNIQUE APPROVAL':'Test','status'=>'draft']);
  $this->getJson('/api/v1/approval-center?q=OLD%20UNIQUE%20APPROVAL')->assertOk()->assertJsonCount(1,'data.pending')->assertJsonPath('data.pending.0.code','PAGING-PR-0');
  $this->getJson('/api/v1/approval-center?type=purchase_request&page=4&page_size=20')->assertOk()->assertJsonFragment(['code'=>'PAGING-PR-0']);
 }
 public function test_invoice_payment_and_receivable_pages_keep_full_totals():void {
  $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->withToken(app(\App\Support\JwtTokenService::class)->issue($admin));
  $order=\App\Models\SalesOrder::where('tenant_id',$admin->tenant_id)->firstOrFail();
  for($i=0;$i<105;$i++){
   $invoice=\App\Models\SalesInvoice::create(['tenant_id'=>$admin->tenant_id,'code'=>'PAGING-INV-'.$i,'sales_order_id'=>$order->id,'invoice_date'=>now()->toDateString(),'due_date'=>now()->subDay()->toDateString(),'total_amount'=>100,'paid_amount'=>20,'balance_amount'=>80,'status'=>'partially_paid']);
   \App\Models\CustomerPayment::create(['tenant_id'=>$admin->tenant_id,'code'=>'PAGING-PAY-'.$i,'sales_invoice_id'=>$invoice->id,'sales_order_id'=>$order->id,'amount'=>20,'paid_at'=>now(),'payment_method'=>'cash']);
  }
  $this->getJson('/api/v1/sales-invoices?q=PAGING-INV-0')->assertOk()->assertJsonCount(1,'data');
  $this->getJson('/api/v1/customer-payments?q=PAGING-PAY-0')->assertOk()->assertJsonCount(1,'data');
  $first=$this->getJson('/api/v1/sales-invoices?page_size=20')->assertOk()->json('meta.stats.outstanding');
  $last=$this->getJson('/api/v1/sales-invoices?page_size=20&page=6')->assertOk()->assertJsonFragment(['code'=>'PAGING-INV-0'])->json('meta.stats.outstanding');
  $this->assertSame($first,$last);$this->assertGreaterThanOrEqual(8400,(float)$last);
  $this->getJson('/api/v1/customer-receivables?status=overdue')->assertOk()->assertJsonFragment(['id'=>$order->customer_id]);
 }

}
