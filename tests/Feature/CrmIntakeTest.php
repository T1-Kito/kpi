<?php

namespace Tests\Feature;

use App\Models\{Customer,CustomerContact,CustomerGroup,Deal,ServiceInterest,Task,User};
use App\Support\{CrmIntakeWorkbook,JwtTokenService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt,DB};
use Tests\TestCase;

class CrmIntakeTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $email='admin@vk-kpi.local'):User
    {
        $this->seed();$user=User::where('email',$email)->firstOrFail();
        $this->withToken(app(JwtTokenService::class)->issue($user));
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        return $user;
    }
    private function row(array $values=[]):array
    {
        return array_replace(array_fill_keys(array_keys(CrmIntakeWorkbook::LABELS),''),['external_ref'=>'THIRD-001','company_name'=>'Trường kiểm thử tổng hợp','tax_code'=>'0312345678','contact_name'=>'Nguyễn An','position'=>'Hiệu trưởng','phone'=>'0901234511','email'=>'an.intake@example.com','owner'=>'admin@vk-kpi.local'],$values);
    }
    private function file(array $rows,?callable $edit=null):UploadedFile
    {
        $path=app(\App\Services\Kpi\KpiReportWorkbook::class)->writeSheets([['PHÂN NGUỒN DỮ LIỆU',array_merge([array_values(CrmIntakeWorkbook::LABELS)],array_map('array_values',$rows)),1]]);
        if($edit){$zip=new \ZipArchive;$zip->open($path);$edit($zip);$zip->close();}
        return new UploadedFile($path,'nhap.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
    }
    private function preview(array $rows):\Illuminate\Testing\TestResponse
    {
        $response=$this->post('/api/v1/crm-intake/preview',['source_name'=>'Danh sách khách kiểm thử','file'=>$this->file($rows)],['Accept'=>'application/json']);
        $this->assertSame(200,$response->status(),$response->getContent());
        return $response;
    }
    private function commit(string $token):\Illuminate\Testing\TestResponse {return $this->postJson('/api/v1/crm-intake/commit',['token'=>$token,'confirmed'=>true]);}

    public function test_all_columns_preview_two_needs_one_customer_contacts_tasks_and_reimport():void
    {
        $actor=$this->actor();
        $g=CustomerGroup::create(['tenant_id'=>$actor->tenant_id,'name'=>'Khách đoàn','is_active'=>true]);
        $s=ServiceInterest::create(['tenant_id'=>$actor->tenant_id,'name'=>'Dịch vụ đoàn','is_active'=>true]);
        ServiceInterest::create(['tenant_id'=>$actor->tenant_id,'parent_id'=>$s->id,'name'=>'Tour tham quan','is_active'=>true]);
        $base=$this->row(['created_date'=>'01/10/2026','group1'=>'Khách đoàn','service1'=>'Dịch vụ đoàn','service2'=>'Tour tham quan','channel1'=>'Offline','channel2'=>'Giới thiệu','person_name'=>'Nguyễn An','province'=>'Đồng Nai','source'=>'Đối tác','contact_channel'=>'Zalo','interests'=>'Tour, bữa trưa','purpose'=>'Ngoại khóa','group_size'=>'Trường học','guest_count'=>'180','service_date'=>'20/03/2027','budget'=>'90000000','crm_status'=>'Đã chuyển đổi','last_contact'=>now()->subDay()->format('d/m/Y'),'next_contact'=>now()->addDays(2)->format('d/m/Y'),'amount'=>'100000000','probability'=>'0.8','forecast'=>'1','special_requests'=>'Xe đưa đón','note'=>'Giữ thông tin nguồn']);
        $second=array_replace($base,['service_date'=>'20/04/2027','guest_count'=>'120']);
        $before=Customer::count();$preview=$this->preview([$base,$second])->assertJsonPath('data.error_count',0)->assertJsonPath('data.rows.0.customer_action','create')->assertJsonPath('data.rows.1.customer_action','reuse')->assertJsonPath('data.rows.0.probability',80)->assertJsonPath('data.rows.0.forecast',80000000);
        $this->assertSame($before,Customer::count());$this->assertSame(0,DB::table('crm_intake_rows')->count());
        $this->commit($preview->json('data.token'))->assertOk()->assertJsonPath('data.customers_created',1)->assertJsonPath('data.opportunities_created',2)->assertJsonPath('data.contacts_created',1)->assertJsonPath('data.tasks_created',2);
        $customer=Customer::where('name',$base['company_name'])->firstOrFail();
        $this->assertStringStartsWith('CUS-',$customer->code);$this->assertNotEquals($base['external_ref'],$customer->code);$this->assertFalse($customer->is_shared);$this->assertEquals($g->id,$customer->customer_group_id);
        $deals=Deal::where('customer_id',$customer->id)->orderBy('id')->get();$this->assertCount(2,$deals);
        $this->assertEquals(180,$deals[0]->needs['guest_count']);$this->assertEquals(120,$deals[1]->needs['guest_count']);
        $this->assertEquals('2027-03-20',$deals[0]->needs['service_date']);$this->assertNull($deals[0]->expected_close_date);$this->assertSame('open',$deals[0]->status);$this->assertNull($deals[0]->won_at);
        $this->assertSame($base,$deals[0]->needs['intake']);
        $this->assertSame($base,json_decode(Crypt::decryptString(DB::table('crm_intake_rows')->orderBy('id')->value('encrypted_payload')),true));
        $this->getJson('/api/v1/crm-intake/history')->assertOk()->assertJsonPath('meta.total',2)->assertJsonPath('data.1.values.channel2','Giới thiệu');
        $this->commit($preview->json('data.token'))->assertConflict();
        $again=$this->preview([$base,$second])->assertJsonPath('data.rows.0.action','skip');
        $this->commit($again->json('data.token'))->assertOk()->assertJsonPath('data.skipped',2)->assertJsonPath('data.opportunities_created',0);
        $changed=$base;$changed['amount']='110000000';
        $this->preview([$changed])->assertJsonPath('data.error_count',1)->assertJsonPath('data.can_commit',false);
        $this->assertSame(2,DB::table('crm_intake_rows')->count());
        $this->putJson('/api/v1/deals/'.$deals[0]->id.'/needs',['version'=>$deals[0]->version,'needs'=>['guest_count'=>200]])->assertOk()->assertJsonPath('data.needs.intake.channel1','Offline');
    }

    public function test_contact_only_duplicate_rows_and_no_schedule_do_not_create_opportunities():void
    {
        $this->actor();$d=$this->row();
        $p=$this->preview([$d,$d])->assertJsonPath('data.rows.0.action','customer')->assertJsonPath('data.rows.1.action','skip');
        $this->commit($p->json('data.token'))->assertOk()->assertJsonPath('data.customers_created',1)->assertJsonPath('data.opportunities_created',0)->assertJsonPath('data.tasks_created',0)->assertJsonPath('data.skipped',1);
        $this->assertSame(1,DB::table('crm_intake_rows')->count());
    }

    public function test_existing_customer_is_reused_without_overwrite_and_contact_is_attached_to_it():void
    {
        $actor=$this->actor();
        $c=Customer::create(['tenant_id'=>$actor->tenant_id,'code'=>'CUS-EXISTING','name'=>'Trường kiểm thử tổng hợp','customer_type'=>'organization','tax_code'=>'0312345678','sales_owner_id'=>$actor->id,'created_by'=>$actor->id,'is_shared'=>true,'status'=>'active']);
        $before=$c->fresh()->getAttributes();$d=$this->row(['purpose'=>'Chuyến mới','service_date'=>'2027-05-01']);
        $p=$this->preview([$d])->assertJsonPath('data.rows.0.customer_code','CUS-EXISTING');
        $this->commit($p->json('data.token'))->assertOk()->assertJsonPath('data.customers_reused',1)->assertJsonPath('data.customers_created',0);
        $this->assertSame($before,$c->fresh()->getAttributes());
        $this->assertDatabaseHas('customer_contacts',['customer_id'=>$c->id,'name'=>'Nguyễn An','is_primary'=>false]);
    }

    public function test_missing_names_unknown_owner_group_service_status_and_conflicting_identity_block_all():void
    {
        $actor=$this->actor();
        $a=Customer::create(['tenant_id'=>$actor->tenant_id,'code'=>'CUS-A','name'=>'A','customer_type'=>'organization','tax_code'=>'8888888888','created_by'=>$actor->id,'status'=>'active']);
        Customer::create(['tenant_id'=>$actor->tenant_id,'code'=>'CUS-B','name'=>'B','customer_type'=>'organization','email'=>'other@example.com','created_by'=>$actor->id,'status'=>'active']);
        $rows=[
            $this->row(['company_name'=>'','contact_name'=>'Không được làm tên đơn vị']),
            $this->row(['owner'=>'Tài khoản không tồn tại']),$this->row(['group1'=>'Nhánh không tồn tại']),
            $this->row(['service1'=>'Dịch vụ không tồn tại']),$this->row(['crm_status'=>'Đang xác minh']),
            $this->row(['tax_code'=>'8888888888','email'=>'other@example.com']),$this->row(['guest_count'=>'1.5']),
        ];
        $before=Customer::count();$p=$this->preview($rows)->assertJsonPath('data.error_count',7)->assertJsonPath('data.token',null);
        $this->assertSame($before,Customer::count());$this->assertSame(0,DB::table('crm_intake_rows')->count());
        foreach($p->json('data.rows') as $r)$this->assertNotEmpty($r['errors']);
    }

    public function test_source_formulas_and_identifiers_dates_and_forecast_are_handled_safely():void
    {
        $this->actor();$row=$this->row(['purpose'=>'Đi chơi','amount'=>'100','probability'=>'25%','forecast'=>'25']);
        $file=$this->file([$row],function($zip){$raw=$zip->getFromName('xl/worksheets/sheet1.xml');$raw=preg_replace('~<c\b[^>]*\br="AF2"[^>]*>.*?</c>~s','<c r="AF2"><f>AD2*AE2</f><v>25</v></c>',$raw);$zip->addFromString('xl/worksheets/sheet1.xml',$raw);});
        $this->post('/api/v1/crm-intake/preview',['source_name'=>'Kiểm thử','file'=>$file],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.error_count',0);
        $bad=$this->file([$row],function($zip){$raw=$zip->getFromName('xl/worksheets/sheet1.xml');$raw=preg_replace('~<c\b[^>]*\br="C2"[^>]*>.*?</c>~s','<c r="C2"><f>A39</f><v>B2C</v></c>',$raw);$zip->addFromString('xl/worksheets/sheet1.xml',$raw);});
        $badResult=$this->post('/api/v1/crm-intake/preview',['source_name'=>'Kiểm thử','file'=>$bad],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.error_count',1);
        $this->assertStringContainsString('công thức',implode(' ',$badResult->json('data.rows.0.errors')));
        $this->preview([$this->row(['service_date'=>'31/02/2027'])])->assertJsonPath('data.error_count',1);
        $this->preview([$this->row(['probability'=>'101'])])->assertJsonPath('data.error_count',1);
    }

    public function test_token_expiry_tampering_confirmation_and_data_changes_are_rejected():void
    {
        $actor=$this->actor();$p=$this->preview([$this->row()]);$token=$p->json('data.token');
        $this->postJson('/api/v1/crm-intake/commit',['token'=>$token,'confirmed'=>false])->assertUnprocessable();
        $this->commit('bad-token')->assertUnprocessable();
        $payload=json_decode(Crypt::decryptString($token),true);$payload['expires']=time()-1;
        $this->commit(Crypt::encryptString(json_encode($payload)))->assertUnprocessable();
        User::whereKey($actor->id)->update(['name'=>'Tên thay đổi']);
        $this->commit($token)->assertConflict();$this->assertSame(0,DB::table('crm_intake_rows')->count());
    }

    public function test_private_customer_wrong_actor_and_foreign_tenant_are_not_exposed():void
    {
        $admin=$this->actor();$p=$this->preview([$this->row()]);
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $sales->roles()->first()->permissions()->syncWithoutDetaching([\App\Models\Permission::where('code','customer.manage')->value('id')]);
        Customer::create(['tenant_id'=>$admin->tenant_id,'code'=>'CUS-SECRET','name'=>'Trường kiểm thử tổng hợp','customer_type'=>'organization','tax_code'=>'0312345678','created_by'=>$admin->id,'is_shared'=>false,'status'=>'active']);
        $this->withToken(app(JwtTokenService::class)->issue($sales));
        $this->commit($p->json('data.token'))->assertForbidden();
        $r=$this->preview([$this->row(['owner'=>'sales@vk-kpi.local'])])->assertJsonPath('data.error_count',1)->assertDontSee('CUS-SECRET');
        $this->assertStringContainsString('ngoài phạm vi',implode(' ',$r->json('data.rows.0.errors')));
        $this->getJson('/api/v1/crm-intake/history')->assertOk()->assertJsonPath('meta.total',0);
    }

    public function test_mid_commit_failure_rolls_back_customer_contact_deal_task_and_receipt():void
    {
        $this->actor();$d=$this->row(['purpose'=>'Đi chơi','next_contact'=>now()->addDay()->format('d/m/Y')]);$p=$this->preview([$d]);
        $counts=[Customer::count(),CustomerContact::count(),Deal::count(),Task::count()];
        $this->mock(\App\Support\TaskService::class)->shouldReceive('create')->once()->andThrow(new \RuntimeException('INTAKE_TEST_FAULT'));
        $this->commit($p->json('data.token'))->assertStatus(500);
        $this->assertSame($counts,[Customer::count(),CustomerContact::count(),Deal::count(),Task::count()]);$this->assertSame(0,DB::table('crm_intake_rows')->count());
    }

    public function test_real_customer_workbook_is_preview_only_and_reports_unsafe_sample_rows():void
    {
        $this->actor();$path='C:/Users/Admin/Downloads/NHẬP LIỆU - PHÂN LOẠI (1).xlsx';if(!is_file($path))$this->markTestSkipped('Customer workbook is local only.');
        $before=Customer::count();
        $result=$this->post('/api/v1/crm-intake/preview',['source_name'=>'File khách gửi','file'=>new UploadedFile($path,'phan-loai.xlsx',null,null,true)],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.can_commit',false);
        $this->assertSame(8,count($result->json('data.rows')));$this->assertGreaterThan(0,$result->json('data.ignored'));
        $this->assertSame(8,$result->json('data.error_count'));$this->assertSame($before,Customer::count());
    }

    public function test_conflicting_contacts_within_file_and_reversed_dates_block_the_batch():void
    {
        $this->actor();$d=$this->row(['purpose'=>'Chuyến 1','service_date'=>'2027-01-01']);
        $p=$this->preview([$d,array_replace($d,['contact_name'=>'Tên khác','service_date'=>'2027-02-01'])])->assertJsonPath('data.error_count',1)->assertJsonPath('data.token',null);
        $this->assertStringContainsString('người liên hệ',implode(' ',$p->json('data.rows.1.errors')));
        $this->preview([$this->row(['last_contact'=>'02/01/2020','next_contact'=>'01/01/2020'])])->assertJsonPath('data.error_count',1);
        $this->preview([$this->row(['created_date'=>'01/01/2090'])])->assertJsonPath('data.error_count',1);
        $this->assertSame(0,DB::table('crm_intake_rows')->count());
    }

    public function test_numeric_excel_dates_are_converted_but_numeric_phone_is_rejected():void
    {
        $this->actor();$d=$this->row();
        $dateFile=$this->file([$d],function($zip){$raw=$zip->getFromName('xl/worksheets/sheet1.xml');$raw=preg_replace('~<c\b[^>]*\br="X2"[^>]*>.*?</c>~s','<c r="X2"><v>46334.5</v></c>',$raw);$zip->addFromString('xl/worksheets/sheet1.xml',$raw);});
        $p=$this->post('/api/v1/crm-intake/preview',['source_name'=>'Ngày Excel','file'=>$dateFile],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.error_count',0)->assertJsonPath('data.rows.0.values.service_date','2026-11-08 12:00:00');
        $numericPhone=$this->file([$d],function($zip){$raw=$zip->getFromName('xl/worksheets/sheet1.xml');$raw=preg_replace('~<c\b[^>]*\br="O2"[^>]*>.*?</c>~s','<c r="O2"><v>901234511</v></c>',$raw);$zip->addFromString('xl/worksheets/sheet1.xml',$raw);});
        $this->post('/api/v1/crm-intake/preview',['source_name'=>'Số nhận diện','file'=>$numericPhone],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.error_count',1);
    }

    public function test_macro_external_link_dtd_wrong_sheet_and_wrong_headers_are_refused():void
    {
        $this->actor();$d=$this->row();
        $mutations=[
            fn($z)=>$z->addFromString('xl/vbaProject.bin','no macro execution'),
            fn($z)=>$z->addFromString('xl/externalLinks/externalLink1.xml','<link/>'),
            fn($z)=>$z->addFromString('xl/worksheets/sheet1.xml','<!DOCTYPE x [<!ENTITY test SYSTEM "file:///C:/not-read">]><worksheet/>'),
            fn($z)=>$z->addFromString('xl/workbook.xml',str_replace('PHÂN NGUỒN DỮ LIỆU','Trang khác',$z->getFromName('xl/workbook.xml'))),
            fn($z)=>$z->addFromString('xl/worksheets/sheet1.xml',str_replace('Mã KH','Mã sai',$z->getFromName('xl/worksheets/sheet1.xml'))),
        ];
        foreach($mutations as $edit)$this->post('/api/v1/crm-intake/preview',['source_name'=>'File lỗi','file'=>$this->file([$d],$edit)],['Accept'=>'application/json'])->assertUnprocessable();
        $this->assertSame(0,DB::table('crm_intake_rows')->count());
    }

    public function test_foreign_company_identifiers_are_not_used_and_cannot_read_the_history():void
    {
        $actor=$this->actor();$other=\App\Models\Tenant::create(['code'=>'INTAKE-OTHER','name'=>'Công ty khác']);
        $foreign=User::create(['tenant_id'=>$other->id,'name'=>'Khác','email'=>'intake.foreign@example.com','password'=>'test-password-123','is_active'=>true]);
        Customer::create(['tenant_id'=>$other->id,'code'=>'SECRET-OTHER','name'=>'Khách bí mật','customer_type'=>'organization','tax_code'=>'0312345678','created_by'=>$foreign->id,'is_shared'=>true,'status'=>'active']);
        $p=$this->preview([$this->row()])->assertJsonPath('data.error_count',0)->assertJsonPath('data.rows.0.customer_action','create')->assertDontSee('SECRET-OTHER');
        $role=\App\Models\Role::create(['tenant_id'=>$other->id,'code'=>'ROLE-ADMIN','name'=>'Quản trị khác','data_scope'=>'company']);
        $role->permissions()->sync(\App\Models\Permission::whereIn('code',['master.view','customer.manage','sales.deal.manage','sales.deal.view'])->pluck('id'));
        $foreign->roles()->attach($role);
        $this->withToken(app(JwtTokenService::class)->issue($foreign->fresh()));
        $this->commit($p->json('data.token'))->assertForbidden();
        $this->getJson('/api/v1/crm-intake/history')->assertOk()->assertJsonPath('meta.total',0);
    }

    public function test_contact_schedule_requires_assignee_to_be_able_to_see_the_customer():void
    {
        $actor=$this->actor();$d=$this->row(['owner'=>'sales@vk-kpi.local','next_contact'=>now()->addDay()->format('d/m/Y')]);
        $this->preview([$d])->assertJsonPath('data.error_count',1);
        Customer::create(['tenant_id'=>$actor->tenant_id,'code'=>'CUS-SHARED','name'=>$d['company_name'],'customer_type'=>'organization','tax_code'=>$d['tax_code'],'created_by'=>$actor->id,'is_shared'=>true,'status'=>'active']);
        $p=$this->preview([$d])->assertJsonPath('data.error_count',0);
        $this->commit($p->json('data.token'))->assertOk()->assertJsonPath('data.tasks_created',1)->assertJsonPath('data.opportunities_created',0);
    }
}
