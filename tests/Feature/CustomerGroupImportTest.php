<?php
namespace Tests\Feature;

use App\Models\{CustomerGroup,User};
use App\Services\Kpi\KpiReportWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CustomerGroupImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $actor=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $this->withToken(app(\App\Support\JwtTokenService::class)->issue($actor));
        return $actor;
    }

    private function file(array $rows): UploadedFile
    {
        $path=app(KpiReportWorkbook::class)->writeSheets([['Danh sách',array_merge([array_values(\App\Support\CustomerGroupImport::LABELS)],$rows),1]]);
        return new UploadedFile($path,'nhom.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
    }

    public function test_preview_commit_reuses_parents_and_rejects_replay_and_changed_catalog(): void
    {
        $actor=$this->admin();
        $response=$this->post('/api/v1/customer-groups/preview',['file'=>$this->file([['Đoàn','Trường','Tiểu học'],['Đoàn','Trường','Trung học']])],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.create_count',4);
        $this->assertSame(0,CustomerGroup::count());
        $token=$response->json('data.token');
        $this->postJson('/api/v1/customer-groups/commit',['token'=>$token])->assertOk()->assertJsonPath('data.created',4);
        $this->assertSame(4,CustomerGroup::count());
        $this->postJson('/api/v1/customer-groups/commit',['token'=>$token])->assertConflict();
        $result=$this->post('/api/v1/customer-groups/preview',['file'=>$this->file([['Đoàn','Trường','Tiểu học']])],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.create_count',0);
        CustomerGroup::where('name','Đoàn')->update(['name'=>'Đổi tên']);
        $this->postJson('/api/v1/customer-groups/commit',['token'=>$result->json('data.token')])->assertConflict();
    }

    public function test_invalid_paths_inactive_parent_and_staff_import_are_rejected(): void
    {
        $actor=$this->admin();
        $this->post('/api/v1/customer-groups/preview',['file'=>$this->file([['Nhóm','','Bỏ cấp']])],['Accept'=>'application/json'])->assertUnprocessable()->assertJsonPath('fields.file.0','Dòng 2: Có Nhóm cấp 3 thì phải nhập Nhóm cấp 2.');
        $this->post('/api/v1/customer-groups/preview',['file'=>$this->file([['Nhóm','',''],['nhóm','','']])],['Accept'=>'application/json'])->assertUnprocessable();
        CustomerGroup::create(['tenant_id'=>$actor->tenant_id,'name'=>'Ngừng','is_active'=>false]);
        $this->post('/api/v1/customer-groups/preview',['file'=>$this->file([['Ngừng','Con','']])],['Accept'=>'application/json'])->assertUnprocessable();
        $staff=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $this->withToken(app(\App\Support\JwtTokenService::class)->issue($staff));
        $this->getJson('/api/v1/customer-groups')->assertOk()->assertJsonPath('can_manage',false);
        $this->post('/api/v1/customer-groups/preview',['file'=>$this->file([['Không được','','']])],['Accept'=>'application/json'])->assertForbidden();
        $this->postJson('/api/v1/customer-groups/commit',['token'=>'sai'])->assertForbidden();
    }

    public function test_template_has_vietnamese_headers_and_page_has_navigation(): void
    {
        $this->admin();
        $response=$this->get('/api/v1/customer-groups/template')->assertOk();
        $zip=new \ZipArchive; $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Nhóm cấp 1',$zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertStringContainsString('HƯỚNG DẪN',$zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();
        $this->get('/customer-groups')->assertOk()->assertSee('customerGroupsRoot')->assertSee('Nhóm khách hàng');
    }

    public function test_mixed_depths_case_spaces_same_name_in_different_branches_and_repeat_import(): void
    {
        $this->admin();
        $rows=[['  Khách đoàn  ','',''],['khách ĐOÀN','Trường học',''],['Khách đoàn','Trường học','Tiểu học'],['Khách lẻ','Trường học','Tiểu học']];
        $result=$this->post('/api/v1/customer-groups/preview',['file'=>$this->file($rows)],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.create_count',6);
        $this->postJson('/api/v1/customer-groups/commit',['token'=>$result->json('data.token')])->assertOk()->assertJsonPath('data.created',6);
        $again=$this->post('/api/v1/customer-groups/preview',['file'=>$this->file($rows)],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.create_count',0);
        $this->postJson('/api/v1/customer-groups/commit',['token'=>$again->json('data.token')])->assertOk()->assertJsonPath('data.created',0);
        $this->assertSame(6,CustomerGroup::count());
        $this->assertSame(2,CustomerGroup::where('name','Tiểu học')->count());
    }

    public function test_invalid_row_rejects_entire_file_without_partial_writes(): void
    {
        $this->admin();
        foreach ([['','Con',''],['Cha','','Cháu'],[str_repeat('a',101),'','']] as $bad) {
            $this->post('/api/v1/customer-groups/preview',['file'=>$this->file([['Hợp lệ','',''],$bad])],['Accept'=>'application/json'])->assertUnprocessable();
            $this->assertSame(0,CustomerGroup::count());
        }
    }

    public function test_file_bounds_empty_wrong_headers_formula_corrupt_and_unsupported_file(): void
    {
        $this->admin();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->post('/api/v1/customer-groups/preview',[],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/v1/customer-groups/preview',['file'=>UploadedFile::fake()->create('nhom.csv',1)],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/v1/customer-groups/preview',['file'=>UploadedFile::fake()->create('nhom.xlsx',1025)],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/v1/customer-groups/preview',['file'=>UploadedFile::fake()->createWithContent('nhom.xlsx','not a workbook')],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/v1/customer-groups/preview',['file'=>$this->file([])],['Accept'=>'application/json'])->assertUnprocessable();
        $many=[]; for($i=1;$i<=301;$i++)$many[]=['Nhóm '.$i,'',''];
        $this->post('/api/v1/customer-groups/preview',['file'=>$this->file($many)],['Accept'=>'application/json'])->assertUnprocessable();
        array_pop($many);
        $this->post('/api/v1/customer-groups/preview',['file'=>$this->file($many)],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.create_count',300);
        foreach (['headers','formula','macro','external'] as $kind) {
            $file=$this->file([['Nhóm','','']]);$zip=new \ZipArchive;$zip->open($file->getRealPath());
            $xml=$zip->getFromName('xl/worksheets/sheet1.xml');
            if($kind==='headers')$xml=str_replace('Nhóm cấp 1','Tên khác',$xml);
            if($kind==='formula')$xml=str_replace('<row r="2">','<row r="2"><c r="D2"><f>1+1</f><v>2</v></c>',$xml);
            $zip->addFromString('xl/worksheets/sheet1.xml',$xml);
            if($kind==='macro')$zip->addFromString('xl/vbaProject.bin','blocked');
            if($kind==='external')$zip->addFromString('xl/externalLinks/externalLink1.xml','blocked');
            $zip->close();
            $this->post('/api/v1/customer-groups/preview',['file'=>$file],['Accept'=>'application/json'])->assertUnprocessable();
        }
        $this->assertSame(0,CustomerGroup::count());
    }

    public function test_preview_token_expiry_tampering_actor_and_tenant_isolation(): void
    {
        $actor=$this->admin();
        $response=$this->post('/api/v1/customer-groups/preview',['file'=>$this->file([['Mới','','']])],['Accept'=>'application/json'])->assertOk();
        $token=$response->json('data.token');
        $this->postJson('/api/v1/customer-groups/commit',['token'=>$token.'x'])->assertUnprocessable();
        $payload=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($token),true);
        foreach(['expires'=>time()-1,'actor'=>$actor->id+1000,'tenant'=>$actor->tenant_id+1000] as $key=>$value){
            $changed=$payload;$changed[$key]=$value;
            $this->postJson('/api/v1/customer-groups/commit',['token'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($changed))])->assertStatus($key==='expires'?422:403);
        }
        CustomerGroup::create(['tenant_id'=>$actor->tenant_id+1000,'name'=>'Mới','is_active'=>true]);
        $this->postJson('/api/v1/customer-groups/commit',['token'=>$token])->assertOk()->assertJsonPath('data.created',1);
        $this->assertSame(1,CustomerGroup::where('tenant_id',$actor->tenant_id)->count());
    }

    public function test_customer_supplied_workbook_compatibility_is_explicit(): void
    {
        $path='C:/Users/Admin/Downloads/NHẬP LIỆU - PHÂN LOẠI (1).xlsx';
        if(!is_file($path))$this->markTestSkipped('File khách gửi chỉ có trên máy kiểm tra local.');
        $this->admin();
        $file=new UploadedFile($path,basename($path),'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
        // Only the explicitly recognized classification table is read, never sample customer rows.
        $preview=$this->post('/api/v1/customer-groups/preview',['file'=>$file],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.create_count',52)->assertJsonCount(25,'data.rows');
        $this->assertSame(0,CustomerGroup::count());
        $customerCount=\App\Models\Customer::count();
        $this->postJson('/api/v1/customer-groups/commit',['token'=>$preview->json('data.token')])->assertOk()->assertJsonPath('data.created',52);
        $this->assertSame(52,CustomerGroup::count());
        $this->assertSame($customerCount,\App\Models\Customer::count());
    }

    public function test_all_25_classification_paths_from_actual_workbook_can_be_imported_using_three_column_contract(): void
    {
        $path='C:/Users/Admin/Downloads/NHẬP LIỆU - PHÂN LOẠI (1).xlsx';
        if(!is_file($path))$this->markTestSkipped('Không có file nguồn trên máy chạy test.');
        $this->admin();
        $zip=new \ZipArchive;$zip->open($path);
        $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';$strings=[];
        $shared=simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'));
        foreach($shared->children($ns)->si as $si){$si->registerXPathNamespace('m',$ns);$strings[]=implode('',array_map('strval',$si->xpath('.//m:t')));}
        $sheet=simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));$sheet->registerXPathNamespace('m',$ns);
        $rows=[];
        for($line=5;$line<=29;$line++){
            $values=[];
            foreach(['A','B','C'] as $column){
                $cell=$sheet->xpath('//m:c[@r="'.$column.$line.'"]')[0];
                $values[]=(string)$cell['t']==='s'?$strings[(int)$cell->children($ns)->v]:(string)$cell->children($ns)->v;
            }
            $rows[]=$values;
        }
        $zip->close();
        $this->assertCount(25,$rows);
        $preview=$this->post('/api/v1/customer-groups/preview',['file'=>$this->file($rows)],['Accept'=>'application/json'])->assertOk()->assertJsonCount(25,'data.rows')->assertJsonPath('data.create_count',52);
        $this->postJson('/api/v1/customer-groups/commit',['token'=>$preview->json('data.token')])->assertOk()->assertJsonPath('data.created',52);
        $this->assertSame(7,CustomerGroup::whereNull('parent_id')->count());
        $repeat=$this->post('/api/v1/customer-groups/preview',['file'=>$this->file($rows)],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.create_count',0);
        $this->postJson('/api/v1/customer-groups/commit',['token'=>$repeat->json('data.token')])->assertOk()->assertJsonPath('data.created',0);
        $this->assertSame(52,CustomerGroup::count());
    }

    public function test_rate_limit_is_enforced(): void
    {
        $this->admin();
        for($i=0;$i<10;$i++)$this->post('/api/v1/customer-groups/preview',[],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/v1/customer-groups/preview',[],['Accept'=>'application/json'])->assertStatus(429);
    }
}
