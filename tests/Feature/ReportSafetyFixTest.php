<?php

namespace Tests\Feature;

use App\Models\{AuditLog,Contract,Customer,Deal,Permission,Role,SalesInvoice,SalesOrder,Supplier,User,WarrantyClaim};
use App\Services\Sales\SalesFulfillmentService;
use App\Support\{CodeGenerator,JwtTokenService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReportSafetyFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }
    private function actor(): User { return User::where('email','admin@vk-kpi.local')->firstOrFail(); }
    private function token(User $user): string { return app(JwtTokenService::class)->issue($user); }

    public function test_supplier_tax_is_normalized_and_duplicate_is_rejected(): void
    {
        $token=$this->token($this->actor());
        $id=$this->withToken($token)->postJson('/api/v1/suppliers',['name'=>'Safety supplier','tax_code'=>' 010-999.9999 '])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/suppliers',['name'=>'Duplicate','tax_code'=>'0109999999'])->assertUnprocessable();
        $this->withToken($token)->putJson('/api/v1/suppliers/'.$id,['name'=>'Updated','tax_code'=>'0109999999'])->assertOk();
        $this->assertSame(1,Supplier::where('tax_code','0109999999')->count());
        $this->withToken($token)->postJson('/api/v1/suppliers',['name'=>'No tax A'])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/suppliers',['name'=>'No tax B'])->assertCreated();
    }

    private function warranty(array $replace=[]): array
    {
        return array_replace(['customer_id'=>Customer::firstOrFail()->id,'customer_name'=>'Safety customer','product_name'=>'Model A','serial_numbers'=>['SAFETY-SN'],'warranty_start_at'=>now()->toDateString(),'warranty_months'=>12],$replace);
    }

    public function test_warranty_duplicates_are_rejected_atomically_in_batch_and_across_requests(): void
    {
        $token=$this->token($this->actor());
        $this->withToken($token)->postJson('/api/v1/warranty-claims',$this->warranty(['serial_numbers'=>['SAFETY-SN',' safety-sn ']]))->assertUnprocessable();
        $this->assertSame(0,WarrantyClaim::where('serial_number','SAFETY-SN')->count());
        $this->withToken($token)->postJson('/api/v1/warranty-claims',$this->warranty())->assertCreated();
        $this->withToken($token)->postJson('/api/v1/warranty-claims',$this->warranty(['product_name'=>' model   a ']))->assertUnprocessable();
        $this->assertSame(1,WarrantyClaim::where('serial_number','SAFETY-SN')->count());
    }

    public function test_warranty_status_tracks_start_end_and_current_date_without_rewriting_data(): void
    {
        $this->travelTo(now()->setDate(2026,1,10)->startOfDay());
        $row=$this->withToken($this->token($this->actor()))->postJson('/api/v1/warranty-claims',$this->warranty(['warranty_start_at'=>'2026-01-15','warranty_months'=>1]))->assertCreated()->assertJsonPath('data.0.warranty_status','not_started')->json('data.0');
        $claim=WarrantyClaim::findOrFail($row['id']);
        $this->travelTo(now()->setDate(2026,1,15)); $this->assertSame('in_warranty',$claim->fresh()->warranty_status);
        $this->travelTo(now()->setDate(2026,2,14)); $this->assertSame('in_warranty',$claim->fresh()->warranty_status);
        $this->travelTo(now()->setDate(2026,2,15)); $this->assertSame('out_of_warranty',$claim->fresh()->warranty_status);
    }

    private function invoice(): SalesInvoice
    {
        $actor=$this->actor();
        $order=SalesOrder::create(['tenant_id'=>$actor->tenant_id,'code'=>'SAFE-ORDER','customer_id'=>Customer::firstOrFail()->id,'sales_owner_id'=>$actor->id,'status'=>'invoiced','delivery_status'=>'delivered','total_amount'=>'100']);
        return SalesInvoice::create(['tenant_id'=>$actor->tenant_id,'code'=>'SAFE-INVOICE','sales_order_id'=>$order->id,'invoice_date'=>now(),'subtotal_amount'=>'100','tax_amount'=>0,'total_amount'=>'100','paid_amount'=>0,'balance_amount'=>'100','status'=>'issued']);
    }

    public function test_payment_requires_identity_and_replays_same_form_without_double_collection(): void
    {
        $invoice=$this->invoice(); $token=$this->token($this->actor()); $url='/api/v1/sales-invoices/'.$invoice->id.'/payments';
        $this->withToken($token)->postJson($url,['amount'=>30])->assertUnprocessable();
        $a=$this->withToken($token)->withHeader('Idempotency-Key','safe-payment')->postJson($url,['amount'=>30])->assertCreated()->json('data.id');
        $b=$this->withToken($token)->withHeader('Idempotency-Key','safe-payment')->postJson($url,['amount'=>30])->assertCreated()->json('data.id');
        $this->assertSame($a,$b);
        $this->withToken($token)->withHeader('Idempotency-Key','safe-payment')->postJson($url,['amount'=>31])->assertConflict();
        $this->assertSame(0,bccomp('30',(string)$invoice->fresh()->paid_amount,2));
        $this->withToken($token)->withHeader('Idempotency-Key','second-real-payment')->postJson($url,['amount'=>30])->assertCreated();
        $this->assertSame(0,bccomp('60',(string)$invoice->fresh()->paid_amount,2));
    }

    public function test_old_tokens_stay_revoked_after_unlock_and_password_change(): void
    {
        $admin=$this->actor(); $adminToken=$this->token($admin);
        $this->withToken($adminToken)->postJson('/api/v1/users/'.$admin->id.'/status',['is_active'=>false])->assertUnprocessable();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail(); $old=$this->token($sales);
        $this->withToken($adminToken)->postJson('/api/v1/users/'.$sales->id.'/status',['is_active'=>false])->assertOk();
        $this->withToken($old)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($adminToken)->postJson('/api/v1/users/'.$sales->id.'/status',['is_active'=>true])->assertOk();
        $this->withToken($old)->getJson('/api/v1/me')->assertUnauthorized();
        $new=$this->token($sales->fresh()); $this->withToken($new)->getJson('/api/v1/me')->assertOk();
        $sales->fresh()->update(['password'=>Hash::make('New fixture password only!')]);
        $this->withToken($new)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_logout_revocation_survives_cache_flush_and_preserves_other_sessions(): void
    {
        $actor=$this->actor(); $token=$this->token($actor); $other=$this->token($actor);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        \Illuminate\Support\Facades\Cache::flush();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($other)->getJson('/api/v1/me')->assertOk();
    }

    public function test_company_reporting_role_does_not_widen_sales_deal_permission(): void
    {
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $role=Role::create(['tenant_id'=>$sales->tenant_id,'code'=>'SAFE-REPORT','name'=>'Report only','data_scope'=>'company']);
        $role->permissions()->attach(Permission::where('code','audit.view')->value('id')); $sales->roles()->attach($role);
        $admin=$this->actor();
        $deal=Deal::create(['tenant_id'=>$admin->tenant_id,'code'=>'SAFE-DEAL','name'=>'Other owner','owner_id'=>$admin->id,'stage'=>'new','status'=>'open','version'=>1,'amount'=>0,'probability'=>10]);
        $this->withToken($this->token($sales))->getJson('/api/v1/deals/'.$deal->id)->assertNotFound();
        $this->assertSame('own',$sales->fresh()->dataScope('sales.deal.view'));
        $this->assertSame('company',$sales->fresh()->dataScope('audit.view'));
        $this->assertSame('own',$sales->fresh()->dataScope());
    }

    public function test_code_is_reserved_and_not_reused_after_record_is_deleted(): void
    {
        $codes=app(CodeGenerator::class); $scope=fn($q)=>$q->where('tenant_id',$this->actor()->tenant_id);
        $a=$codes->next('suppliers','code','SAFE-',$scope); $b=$codes->next('suppliers','code','SAFE-',$scope);
        $this->assertNotSame($a,$b);
        $supplier=Supplier::create(['tenant_id'=>$this->actor()->tenant_id,'code'=>$b,'name'=>'Delete fixture']); $supplier->delete();
        $c=$codes->next('suppliers','code','SAFE-',$scope); $this->assertNotContains($c,[$a,$b]);
    }

    public function test_contract_actions_write_audit_once_with_actor_and_state(): void
    {
        $actor=$this->actor(); $token=$this->token($actor);
        $contract=Contract::create(['tenant_id'=>$actor->tenant_id,'code'=>'SAFE-CONTRACT','name'=>'Safety','customer_id'=>Customer::firstOrFail()->id,'owner_id'=>$actor->id,'total_amount'=>100,'status'=>'draft']);
        $milestone=$contract->milestones()->create(['name'=>'Safety','amount'=>100,'status'=>'pending']);
        $this->withToken($token)->postJson('/api/v1/contracts/'.$contract->id.'/activate')->assertOk();
        $url='/api/v1/contracts/'.$contract->id.'/milestones/'.$milestone->id.'/accept';
        $this->withToken($token)->postJson($url)->assertOk(); $this->withToken($token)->postJson($url)->assertOk();
        $log=AuditLog::where('action','activate_contract')->firstOrFail();
        $this->assertEquals($actor->id,$log->user_id); $this->assertSame('draft',$log->old_value['status']); $this->assertSame('active',$log->new_value['status']);
        $this->assertSame(1,AuditLog::where('action','accept_contract_milestone')->count());
    }

    public function test_user_manager_cannot_lock_the_last_full_administrator(): void
    {
        $actor=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $role=Role::create(['tenant_id'=>$actor->tenant_id,'code'=>'SAFE-USER-MANAGER','name'=>'Users only','data_scope'=>'company']);
        $role->permissions()->attach(Permission::where('code','user.manage')->value('id')); $actor->roles()->sync([$role->id]);
        $this->withToken($this->token($actor))->postJson('/api/v1/users/'.$this->actor()->id.'/status',['is_active'=>false])->assertUnprocessable();
        $this->assertTrue($this->actor()->is_active);
    }

    public function test_signed_token_with_wrong_issuer_or_tenant_or_missing_version_is_rejected(): void
    {
        $original=explode('.',$this->token($this->actor()));
        $claims=json_decode(base64_decode(strtr($original[1],'-_','+/')),true);
        foreach (['issuer','tenant','version'] as $case) {
            $changed=$claims;
            if ($case==='issuer') $changed['iss']='https://untrusted.example';
            if ($case==='tenant') $changed['tenant_id']++;
            if ($case==='version') unset($changed['token_version']);
            $payload=rtrim(strtr(base64_encode(json_encode($changed)),'+/','-_'),'=');
            $unsigned=$original[0].'.'.$payload;
            $signature=rtrim(strtr(base64_encode(hash_hmac('sha256',$unsigned,config('app.key'),true)),'+/','-_'),'=');
            $this->withToken($unsigned.'.'.$signature)->getJson('/api/v1/me')->assertUnauthorized();
        }
    }
}
