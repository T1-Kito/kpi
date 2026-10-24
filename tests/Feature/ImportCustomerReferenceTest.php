<?php
namespace Tests\Feature;
use App\Models\{Customer,User};
use App\Support\ImportCustomerReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportCustomerReferenceTest extends TestCase
{
    use RefreshDatabase;
    private User $actor;private int $source;private Customer $customer;
    protected function setUp():void {
        parent::setUp();$this->seed();$this->actor=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $this->source=DB::table('import_sources')->insertGetId(['tenant_id'=>$this->actor->tenant_id,'code'=>'REF','name'=>'Tham chiếu','is_active'=>true]);
        DB::table('import_source_users')->insert(['source_id'=>$this->source,'user_id'=>$this->actor->id]);
        $this->customer=Customer::where('tenant_id',$this->actor->tenant_id)->where('customer_type','organization')->firstOrFail();
        $this->customer->update(['status'=>'active','merged_into_id'=>null]);
        DB::table('import_record_links')->insert(['tenant_id'=>$this->actor->tenant_id,'source_id'=>$this->source,'catalog'=>'customers','external_id'=>'ext-001','record_id'=>$this->customer->id,'source_revision'=>1,'source_hash'=>str_repeat('a',64),'crm_hash'=>str_repeat('b',64)]);
    }
    public function test_explicit_crm_and_same_source_external_reference_resolve_same_customer():void {
        $resolver=new ImportCustomerReference;
        $this->assertSame($this->customer->id,$resolver->resolve($this->actor,$this->source,'ext:EXT-001')->id);
        $this->assertSame($this->customer->id,$resolver->resolve($this->actor,$this->source,'crm:'.$this->customer->code)->id);
    }
    public function test_missing_ambiguous_and_wrong_source_references_do_not_guess():void {
        $resolver=new ImportCustomerReference;
        foreach(['ext:unknown','crm:unknown',(string)$this->customer->id,$this->customer->name,'ext:../../outside'] as $reference){
            try{$resolver->resolve($this->actor,$this->source,$reference);$this->fail('Reference must be rejected');}catch(ValidationException $e){$this->assertNotEmpty($e->errors()['customer_ref']);}
        }
        DB::table('import_record_links')->where('record_id',$this->customer->id)->update(['source_id'=>$this->source+100]);
        $this->expectException(ValidationException::class);$resolver->resolve($this->actor,$this->source,'ext:ext-001');
    }
    public function test_inactive_merged_person_or_other_tenant_customer_cannot_be_used():void {
        $resolver=new ImportCustomerReference;
        foreach([['status'=>'inactive'],['merged_into_id'=>$this->customer->id],['customer_type'=>'person']] as $changes){
            $this->customer->update(array_replace(['status'=>'active','merged_into_id'=>null,'customer_type'=>'organization'],$changes));
            try{$resolver->resolve($this->actor,$this->source,'ext:ext-001');$this->fail('Invalid customer must be rejected');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
        }
        DB::table('import_record_links')->where('record_id',$this->customer->id)->update(['tenant_id'=>$this->actor->tenant_id+100]);
        $this->expectException(ValidationException::class);$resolver->resolve($this->actor,$this->source,'ext:ext-001');
    }
    public function test_revoked_source_membership_is_denied():void {
        DB::table('import_source_users')->where('source_id',$this->source)->delete();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new ImportCustomerReference)->resolve($this->actor,$this->source,'crm:'.$this->customer->code);
    }
}
