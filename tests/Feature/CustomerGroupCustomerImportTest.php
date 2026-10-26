<?php
namespace Tests\Feature;

use App\Models\{Customer,CustomerGroup,User};
use App\Support\{CustomerSourceImport,CustomerGroupReference};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CustomerGroupCustomerImportTest extends TestCase
{
    use RefreshDatabase;
    private User $actor;
    private array $groups;
    protected function setUp():void
    {
        parent::setUp();$this->seed();$this->actor=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $this->withToken(app(\App\Support\JwtTokenService::class)->issue($this->actor));
        $parent=null;$this->groups=[];
        foreach(['Khách đoàn','Trường học','Tiểu học'] as $name){$g=CustomerGroup::create(['tenant_id'=>$this->actor->tenant_id,'parent_id'=>$parent,'name'=>$name,'is_active'=>true]);$parent=$g->id;$this->groups[]=$g;}
    }
    private function file(array $extra=[]):UploadedFile
    {
        $data=['schema_version'=>'1','external_id'=>'TEST-GROUP-CUSTOMER','source_revision'=>'1','name'=>'Trường mẫu','customer_type'=>'Doanh nghiệp','phone'=>'0908777666','group_level1'=>'Khách đoàn','group_level2'=>'Trường học','group_level3'=>'Tiểu học'];
        $data=array_replace($data,$extra);$headers=CustomerSourceImport::LABELS;
        // Production writer supports only 26 columns; this contract is 19 columns.
        $path=app(\App\Services\Kpi\KpiReportWorkbook::class)->writeSheets([['Danh sách',[array_values($headers),array_map(fn($k)=>$data[$k] ?? '',array_keys($headers))],1]]);
        return new UploadedFile($path,'khach.xlsx',null,null,true);
    }
    private function preview(array $extra=[])
    {
        return $this->post('/api/v1/import-center/customer-preview',['file'=>$this->file($extra)],['Accept'=>'application/json']);
    }
    private function commit($id){return $this->postJson('/api/v1/import-center/batches/'.$id.'/commit',['confirmed'=>true]);}

    public function test_group_is_connected_from_import_to_detail_filter_update_and_legacy_blank():void
    {
        $p=$this->preview()->assertCreated()->assertJsonPath('data.status','ready');
        $this->assertDatabaseMissing('customers',['name'=>'Trường mẫu']);
        $this->commit($p->json('data.id'))->assertOk();
        $customer=Customer::where('phone','0908777666')->firstOrFail();
        $this->assertEquals($this->groups[2]->id,$customer->customer_group_id);
        $this->getJson('/api/v1/customers/'.$customer->id)->assertOk()->assertJsonPath('data.customer_group_id',$this->groups[2]->id);
        $this->getJson('/api/v1/customers?customer_group_id='.$this->groups[0]->id)->assertOk()->assertJsonPath('meta.total',1);
        $update=$this->preview(['source_revision'=>'2','name'=>'Tên mới','group_level1'=>'','group_level2'=>'','group_level3'=>''])->assertCreated()->assertJsonPath('data.status','ready');
        $this->commit($update->json('data.id'))->assertOk();
        $this->assertEquals($this->groups[2]->id,$customer->fresh()->customer_group_id);
        $rootOnly=$this->preview(['source_revision'=>'3','group_level2'=>'','group_level3'=>''])->assertCreated()->assertJsonPath('data.status','ready');
        $this->commit($rootOnly->json('data.id'))->assertOk();
        $this->assertEquals($this->groups[0]->id,$customer->fresh()->customer_group_id);
    }

    public function test_wrong_parent_missing_inactive_and_foreign_groups_do_not_write():void
    {
        foreach([['group_level1'=>'Không tồn tại'],['group_level2'=>'','group_level3'=>'Tiểu học'],['group_level1'=>'Trường học','group_level2'=>'','group_level3'=>'']] as $extra){
            $this->preview($extra)->assertCreated()->assertJsonPath('data.status','invalid');
        }
        $this->groups[1]->update(['is_active'=>false]);
        $this->preview()->assertCreated()->assertJsonPath('data.status','invalid');
        $this->assertDatabaseMissing('customers',['phone'=>'0908777666']);
    }

    public function test_group_disabled_between_preview_and_commit_stops_import():void
    {
        $p=$this->preview()->assertCreated()->assertJsonPath('data.status','ready');
        $this->groups[0]->update(['is_active'=>false]);
        $response=$this->commit($p->json('data.id'));
        $this->assertGreaterThanOrEqual(400,$response->status());
        $this->assertDatabaseMissing('customers',['phone'=>'0908777666']);
    }

    public function test_downloaded_template_has_group_headers_choices_and_preserves_old_fields():void
    {
        $foreign=CustomerGroup::create(['tenant_id'=>$this->actor->tenant_id+1000,'name'=>'Không lộ nhóm khác','is_active'=>true]);
        $response=$this->get('/api/v1/import-center/customer-template')->assertOk();
        $zip=new \ZipArchive;$zip->open($response->baseResponse->getFile()->getPathname());
        $sheet=html_entity_decode($zip->getFromName('xl/worksheets/sheet1.xml'),ENT_XML1,'UTF-8');$refs=$zip->getFromName('xl/worksheets/sheet100.xml');
        $stylesDoc=new \DOMDocument;$stylesDoc->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));$styleXp=new \DOMXPath($stylesDoc);$styleXp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach([1,2,3] as $line){
            $referenceStyle=$styleXp->query('//m:c[@r="N'.$line.'"]')->item(0)->getAttribute('s');
            foreach(['O','P','Q'] as $column)$this->assertSame($referenceStyle,$styleXp->query('//m:c[@r="'.$column.$line.'"]')->item(0)->getAttribute('s'),'Cột nhóm phải giữ màu nền, chữ và đường kẻ theo mẫu.');
        }
        foreach(CustomerGroupReference::LABELS as $label)$this->assertStringContainsString($label,$sheet);
        foreach(['O2:O301','P2:P301','Q2:Q301'] as $range)$this->assertStringContainsString($range,$sheet);
        foreach(['Khách đoàn','Trường học','Tiểu học'] as $label)$this->assertStringContainsString($label,$refs);
        $this->assertStringNotContainsString($foreign->name,$refs);
        $this->assertStringContainsString('Tên khách hàng',$sheet);
        $this->assertStringContainsString('Phân nhóm khách hàng',html_entity_decode($zip->getFromName('xl/worksheets/sheet2.xml'),ENT_XML1,'UTF-8'));
        $zip->close();
        // Fill the actual downloadable workbook, not a handcrafted substitute.
        $path=$response->baseResponse->getFile()->getPathname();$zip->open($path);
        $doc=new \DOMDocument;$doc->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
        $xp=new \DOMXPath($doc);$ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';$xp->registerNamespace('m',$ns);
        $cells=['A2'=>'REAL-DOWNLOAD','B2'=>'Khách từ mẫu tải','C2'=>'Doanh nghiệp','F2'=>'0908111222','O2'=>'Khách đoàn','P2'=>'Trường học','Q2'=>'Tiểu học'];
        // Exercise five rows through the exact template downloaded by employees.
        for($line=3;$line<=6;$line++)$cells+=['A'.$line=>'REAL-DOWNLOAD-'.$line,'B'.$line=>'Khách thử '.$line,'C'.$line=>'Doanh nghiệp','F'.$line=>'09081112'.str_pad((string)$line,2,'0',STR_PAD_LEFT),'O'.$line=>'Khách đoàn','P'.$line=>$line%2?'Trường học':'','Q'.$line=>$line%2?'Tiểu học':''];
        foreach($cells as $ref=>$value){
            $c=$xp->query('//m:c[@r="'.$ref.'"]')->item(0);
            while($c->firstChild)$c->removeChild($c->firstChild);
            $c->setAttribute('t','inlineStr');$is=$doc->createElementNS($ns,'is');$t=$doc->createElementNS($ns,'t');$t->appendChild($doc->createTextNode($value));$is->appendChild($t);$c->appendChild($is);
        }
        $zip->addFromString('xl/worksheets/sheet1.xml',$doc->saveXML());$zip->close();
        $before=Customer::count();
        $p=$this->post('/api/v1/import-center/customer-preview',['file'=>new UploadedFile($path,'mau.xlsx',null,null,true)],['Accept'=>'application/json'])->assertCreated()->assertJsonPath('data.status','ready')->assertJsonPath('data.result.total',5)->assertJsonPath('data.result.preview.0.group_path','Khách đoàn / Trường học / Tiểu học');
        $this->assertSame($before,Customer::count());
        $this->commit($p->json('data.id'))->assertOk();
        $this->assertSame($before+5,Customer::count());
        $this->assertDatabaseHas('customers',['phone'=>'0908111222','customer_group_id'=>$this->groups[2]->id]);
        $this->getJson('/api/v1/customers?customer_group_id='.$this->groups[0]->id)->assertOk()->assertJsonPath('meta.total',5);
        $this->getJson('/api/v1/customers?customer_group_id='.$this->groups[2]->id)->assertOk()->assertJsonPath('meta.total',3);
    }

    public function test_blank_groups_preserve_inactive_existing_group_on_import_update():void
    {
        $p=$this->preview()->assertCreated();$this->commit($p->json('data.id'))->assertOk();
        $this->groups[0]->update(['is_active'=>false]);
        $p=$this->preview(['source_revision'=>'2','name'=>'Giữ phân nhóm ngừng dùng','group_level1'=>'','group_level2'=>'','group_level3'=>''])->assertCreated()->assertJsonPath('data.status','ready');
        $this->commit($p->json('data.id'))->assertOk();
        $this->assertDatabaseHas('customers',['phone'=>'0908777666','name'=>'Giữ phân nhóm ngừng dùng','customer_group_id'=>$this->groups[2]->id]);
    }

    public function test_legacy_mapping_without_group_hash_can_add_group_without_losing_manual_conflict_guard():void
    {
        $p=$this->preview(['group_level1'=>'','group_level2'=>'','group_level3'=>''])->assertCreated();$this->commit($p->json('data.id'))->assertOk();
        $customer=Customer::where('phone','0908777666')->firstOrFail();
        $legacy=$customer->only(array_diff(array_keys(CustomerSourceImport::FIELDS),['sales_owner_ref']));
        $legacy['credit_limit']=(float)$customer->credit_limit;
        $legacy['sales_owner_ref']='crm:'.$this->actor->email;
        $legacy+=['_updated_at'=>$customer->getRawOriginal('updated_at'),'_merged'=>$customer->merged_into_id];
        \Illuminate\Support\Facades\DB::table('import_record_links')->where('record_id',$customer->id)->where('catalog','customers')->update(['crm_hash'=>\App\Support\ImportRevision::hash($legacy)]);
        $p=$this->preview(['source_revision'=>'2'])->assertCreated()->assertJsonPath('data.status','ready');
        $this->commit($p->json('data.id'))->assertOk();
        $this->assertEquals($this->groups[2]->id,$customer->fresh()->customer_group_id);
        $customer->update(['address'=>'Đã sửa tay']);
        $this->preview(['source_revision'=>'3'])->assertCreated()->assertJsonPath('data.status','invalid');
    }

    public function test_mid_customer_write_failure_rolls_back_all_customer_group_assignments():void
    {
        $p=$this->preview()->assertCreated()->assertJsonPath('data.status','ready');
        \App\Models\Customer::created(function($customer){if($customer->phone==='0908777666')throw new \RuntimeException('test-write-failure');});
        try {
            $this->commit($p->json('data.id'))->assertStatus(500);
            $this->assertDatabaseMissing('customers',['phone'=>'0908777666']);
            $this->assertDatabaseHas('import_batches',['id'=>$p->json('data.id'),'status'=>'ready']);
        }finally{\App\Models\Customer::flushEventListeners();}
    }
}
