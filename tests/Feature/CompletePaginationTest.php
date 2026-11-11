<?php
namespace Tests\Feature;
use App\Models\{User,AuditLog,PurchaseRequest,SalesOrder,SalesInvoice,CustomerPayment,Customer,Quotation,Supplier,Sku};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class CompletePaginationTest extends TestCase {
 use RefreshDatabase;
 private function admin():User{$this->seed();$user=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->withToken(app(\App\Support\JwtTokenService::class)->issue($user));return $user;}
 public function test_old_audit_entries_are_searchable_and_every_step_is_accessible():void {
  $user=$this->admin();$old=null;
  for($i=0;$i<125;$i++){$row=AuditLog::create(['tenant_id'=>$user->tenant_id,'user_id'=>$user->id,'entity_type'=>'customer','entity_id'=>$i+1,'action'=>$i===0?'old_unique_action':'update_customer','new_value'=>['code'=>'HISTORY-'.$i],'reason'=>'Test']);$old??=$row;}
  $latest=$row;
  $this->getJson('/api/v1/audit-logs?q=HISTORY-0')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$old->id);
  $this->getJson('/api/v1/audit-logs?page_size=30&page=5')->assertOk()->assertJsonFragment(['id'=>$old->id]);
  $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonFragment(['old_unique_action']);
  $first=$this->getJson('/api/v1/audit-logs/'.$latest->id)->assertOk()->assertJsonPath('data.steps_meta.total',125)->assertJsonCount(60,'data.steps');
  $last=$this->getJson('/api/v1/audit-logs/'.$latest->id.'?steps_page=3')->assertOk()->assertJsonCount(5,'data.steps');
  $this->assertEmpty(array_intersect(array_column($first->json('data.steps'),'id'),array_column($last->json('data.steps'),'id')));
 }
 public function test_approval_search_and_history_are_not_truncated_before_filtering():void {
  $user=$this->admin();
  for($i=0;$i<75;$i++)PurchaseRequest::create(['tenant_id'=>$user->tenant_id,'code'=>'QUEUE-'.$i,'reason'=>'Test queue','status'=>'draft','requested_by'=>$user->id]);
  $this->getJson('/api/v1/approval-center?q=QUEUE-0')->assertOk()->assertJsonCount(1,'data.pending')->assertJsonPath('data.pending.0.code','QUEUE-0');
  $this->getJson('/api/v1/approval-center?type=purchase_request&page=4')->assertOk()->assertJsonFragment(['code'=>'QUEUE-0']);
  PurchaseRequest::where('tenant_id',$user->tenant_id)->where('code','like','QUEUE-%')->update(['status'=>'approved','updated_at'=>now()]);
  $this->getJson('/api/v1/approval-center?q=QUEUE-0')->assertOk()->assertJsonCount(1,'data.recent');
  $this->getJson('/api/v1/approval-center?type=purchase_request&history_page=4')->assertOk()->assertJsonFragment(['code'=>'QUEUE-0']);
 }
 public function test_core_worklist_search_finds_records_beyond_the_first_page():void {
  $user=$this->admin();
  foreach(['quotations'=>Quotation::class,'sales-orders'=>SalesOrder::class,'suppliers'=>Supplier::class,'skus'=>Sku::class] as $endpoint=>$model){
   $original=$model::where('tenant_id',$user->tenant_id)->firstOrFail();$key=$endpoint==='skus'?'sku_code':'code';
   for($i=0;$i<105;$i++){$copy=$original->replicate();$copy->$key='PAGED-'.$i;$copy->save();}
   $this->getJson('/api/v1/'.$endpoint.'?q=PAGED-0')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.'.$key,'PAGED-0');
   $this->getJson('/api/v1/'.$endpoint.'?page=6&page_size=20')->assertOk()->assertJsonFragment([$key=>'PAGED-0']);
  }
 }
 public function test_document_and_stock_filters_are_applied_by_the_server():void {
  $this->admin();
  foreach(['purchase-requests','purchase-orders','goods-receipts','goods-issues','alerts','inventory-balances'] as $endpoint) $this->getJson('/api/v1/'.$endpoint.'?q=NO-SUCH-PAGINATION-RECORD')->assertOk()->assertJsonCount(0,'data');
  foreach(['low','ok','reserved'] as $status) $this->getJson('/api/v1/inventory-balances?status='.$status)->assertOk();
 }
 public function test_finance_totals_do_not_depend_on_page_size_and_status_filters_work():void {
  $user=$this->admin();$order=SalesOrder::where('tenant_id',$user->tenant_id)->firstOrFail();
  for($i=0;$i<105;$i++){$invoice=SalesInvoice::create(['tenant_id'=>$user->tenant_id,'code'=>'BILL-'.$i,'sales_order_id'=>$order->id,'invoice_date'=>now()->toDateString(),'total_amount'=>100,'balance_amount'=>100,'status'=>'issued']);CustomerPayment::create(['tenant_id'=>$user->tenant_id,'code'=>'PAY-'.$i,'sales_invoice_id'=>$invoice->id,'sales_order_id'=>$order->id,'amount'=>10,'paid_at'=>now(),'payment_method'=>'cash']);}
  $one=$this->getJson('/api/v1/sales-invoices?page_size=1')->assertOk();$many=$this->getJson('/api/v1/sales-invoices?page_size=20')->assertOk();$this->assertEquals($one->json('meta.stats'),$many->json('meta.stats'));
  $this->getJson('/api/v1/sales-invoices?q=BILL-0')->assertOk()->assertJsonCount(1,'data');
  $this->getJson('/api/v1/customer-payments?q=PAY-0')->assertOk()->assertJsonCount(1,'data');
  $this->getJson('/api/v1/customer-payments?payment_method=bank_transfer')->assertOk()->assertJsonCount(0,'data');
  $debt=$this->getJson('/api/v1/customer-receivables?page_size=1')->assertOk();$full=$this->getJson('/api/v1/customer-receivables?page_size=20')->assertOk();$this->assertEquals($debt->json('meta.stats'),$full->json('meta.stats'));
  $this->getJson('/api/v1/customer-receivables?status=unpaid')->assertOk()->assertJsonCount(1,'data');
 }
}
