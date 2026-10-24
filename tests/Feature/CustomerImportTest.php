<?php

namespace Tests\Feature;

use App\Models\{Customer,CustomerContact,User};
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }
    private function token(string $email='admin@vk-kpi.local'): string { return app(JwtTokenService::class)->issue(User::where('email',$email)->firstOrFail()); }
    private function file(string $rows, string $header='name,customer_type,phone,email,tax_code,address,contact_name,identity_number'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('customers.csv',"\xEF\xBB\xBF".$header."\n".$rows);
    }
    private function send(string $rows, bool $confirm=false)
    {
        return $this->withToken($this->token())->post('/api/v1/customer-import',['file'=>$this->file($rows),'confirm'=>$confirm?'1':'0'],['Accept'=>'application/json']);
    }

    public function test_preview_does_not_write_and_confirmation_creates_contacts_owner_and_audit_once(): void
    {
        $rows="Công ty Nhập Mẫu,organization,0918111222,import@example.test,0012345678,Hà Nội,Nguyễn An,\nNgười Nhập Mẫu,person,0918333444,,,,,001234567890";
        $this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.10','HTTP_USER_AGENT'=>'Customer import test']);
        $before=Customer::count();
        $this->send($rows)->assertOk()->assertJsonPath('data.can_import',true)->assertJsonPath('data.imported',0)
            ->assertJsonPath('data.preview.0.action','create')->assertJsonPath('data.preview.0.phone','0918111222')
            ->assertJsonPath('data.preview.0.tax_code','0012345678')->assertJsonPath('data.preview.1.identity_number','001234567890');
        $this->assertSame($before,Customer::count());
        $this->send($rows,true)->assertOk()->assertJsonPath('data.imported',2);
        $company=Customer::where('email','import@example.test')->firstOrFail();
        $this->assertSame('0012345678',$company->tax_code);
        $this->assertSame('0918111222',$company->phone);
        $this->assertSame(User::where('email','admin@vk-kpi.local')->value('id'),$company->sales_owner_id);
        $this->assertSame(1,CustomerContact::where('customer_id',$company->id)->where('name','Nguyễn An')->count());
        $this->assertDatabaseHas('audit_logs',['entity_type'=>'customer','entity_id'=>$company->id,'action'=>'create_customer','ip_address'=>'192.0.2.10','user_agent'=>'Customer import test']);
        $this->send($rows,true)->assertOk()->assertJsonPath('data.can_import',false)->assertJsonPath('data.imported',0);
        $this->assertSame($before+2,Customer::count());
    }

    public function test_invalid_or_duplicate_rows_never_partially_import(): void
    {
        $before=Customer::count();
        $this->send("Valid,organization,0918555666,,,,,\nInvalid,organization,,not-an-email,,,,",true)
            ->assertOk()->assertJsonPath('data.can_import',false)->assertJsonPath('data.errors.0.row',3);
        $this->assertSame($before,Customer::count());
        $this->send("One,organization,0918555666,,,,,\nTwo,organization,+84 918 555 666,,,,,",true)
            ->assertOk()->assertJsonPath('data.can_import',false)->assertJsonPath('data.imported',0);
        $this->assertSame($before,Customer::count());
    }
    public function test_preview_includes_every_row_not_just_first_twenty(): void
    {
        $rows=[];for($i=0;$i<25;$i++)$rows[]='Khách '.$i.',organization,,preview'.$i.'@example.test,,,, ';
        $before=Customer::count();
        $response=$this->send(implode("\n",$rows))->assertOk()->assertJsonPath('data.can_import',true)->assertJsonPath('data.preview.24.name','Khách 24');
        $this->assertCount(25,$response->json('data.preview'));
        $this->assertSame($before,Customer::count());
    }

    public function test_existing_hidden_customer_is_not_disclosed_or_overwritten(): void
    {
        $customer=Customer::firstOrFail();
        $customer->update(['phone'=>'0918999888','email'=>'hidden@example.test']);
        $this->send("Other,organization,0918999888,,,,,",true)->assertOk()
            ->assertJsonPath('data.can_import',false)->assertDontSee($customer->name)->assertDontSee('hidden@example.test');
        $this->assertSame($customer->name,$customer->fresh()->name);
    }

    public function test_permissions_template_headers_encoding_and_limits_are_enforced(): void
    {
        $this->getJson('/api/v1/customer-import/template')->assertUnauthorized();
        $this->withToken($this->token('sales@vk-kpi.local'))->get('/api/v1/customer-import/template')->assertForbidden();
        $this->post('/api/v1/customer-import',['file'=>$this->file('Test,organization,0918777888,,,,,')],['Accept'=>'application/json'])->assertForbidden();
        $this->withToken($this->token())->get('/api/v1/customer-import/template')->assertOk()->assertDownload('mau-khach-hang.xlsx');
        $this->post('/api/v1/customer-import',['file'=>$this->file('Name,3','name,sales_owner_id')],['Accept'=>'application/json'])->assertUnprocessable();
        $this->send(str_repeat("Name,organization,0918777888,,,,,\n",301))->assertUnprocessable();
        $this->post('/api/v1/customer-import',['file'=>UploadedFile::fake()->createWithContent('customers.csv',"name\n\xFF")],['Accept'=>'application/json'])->assertUnprocessable();
    }

    public function test_same_identity_in_another_tenant_does_not_block_import(): void
    {
        $tenant=\App\Models\Tenant::create(['code'=>'IMPORT-OTHER','name'=>'Other company']);
        Customer::create(['tenant_id'=>$tenant->id,'code'=>'OTHER-CUSTOMER','name'=>'Private other company','phone'=>'0918000111','customer_type'=>'organization','status'=>'active']);
        $this->send('Local,organization,0918000111,,,,,',true)->assertOk()->assertJsonPath('data.imported',1);
    }

    public function test_unexpected_failure_rolls_back_customers_contacts_and_audit_together(): void
    {
        $before=Customer::count(); $audit=\App\Models\AuditLog::count(); $calls=0;
        $this->mock(\App\Support\BusinessEventPublisher::class,function ($mock) use (&$calls) {
            $mock->shouldReceive('publish')->andReturnUsing(function () use (&$calls) {
                if (++$calls===2) throw new \RuntimeException('Simulated import failure');
                return new \App\Models\BusinessEvent;
            });
        });
        $this->send("Atomic One,organization,0918111001,,,,Contact One,\nAtomic Two,organization,0918111002,,,,Contact Two,",true)->assertStatus(500);
        $this->assertSame($before,Customer::count());
        $this->assertSame($audit,\App\Models\AuditLog::count());
        $this->assertSame(0,CustomerContact::whereIn('name',['Contact One','Contact Two'])->count());
    }

    public function test_semicolon_file_preserves_utf8_and_leading_zero_identifiers(): void
    {
        $this->withToken($this->token())->post('/api/v1/customer-import',[
            'file'=>$this->file('Khách Việt;person;0918000222;;;;;001122334455',
                'name;customer_type;phone;email;tax_code;address;contact_name;identity_number'), 'confirm'=>'1',
        ],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.imported',1);
        $this->assertDatabaseHas('customers',['name'=>'Khách Việt','phone'=>'0918000222','identity_number'=>'001122334455']);
    }

    private function excel(array $values, ?string $mode=null): UploadedFile
    {
        $temporary=tempnam(sys_get_temp_dir(),'crm-import-xlsx-');
        try {
            copy(resource_path('templates/import/mau-khach-hang.xlsx'),$temporary);
            $zip=new \ZipArchive; $zip->open($temporary);
            $doc=new \DOMDocument; $doc->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'),LIBXML_NONET);
            $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $xpath=new \DOMXPath($doc);$xpath->registerNamespace('s',$ns);
            $row=$xpath->query('//s:sheetData/s:row[@r="5"]')->item(0);
            if (!$row) { $row=$doc->createElementNS($ns,'row');$row->setAttribute('r','5');$xpath->query('//s:sheetData')->item(0)->appendChild($row); }
            while($row->firstChild) $row->removeChild($row->firstChild);
            foreach($values as $column=>$value) {
                $cell=$doc->createElementNS($ns,'c');$cell->setAttribute('r',chr(65+$column).'5');
                $cell->setAttribute('t','inlineStr');$inline=$doc->createElementNS($ns,'is');$text=$doc->createElementNS($ns,'t');
                $text->appendChild($doc->createTextNode($value));$inline->appendChild($text);$cell->appendChild($inline);
                if($mode==='formula' && $column===0) $cell->appendChild($doc->createElementNS($ns,'f','2+2'));
                if($mode==='numeric' && $column===2) { while($cell->firstChild)$cell->removeChild($cell->firstChild);$cell->setAttribute('t','n');$cell->appendChild($doc->createElementNS($ns,'v','918222333')); }
                $row->appendChild($cell);
            }
            $zip->addFromString('xl/worksheets/sheet1.xml',$doc->saveXML());
            if($mode==='oversize')$zip->addFromString('xl/oversize.xml',str_repeat('x',3*1024*1024));
            $zip->close();
            return UploadedFile::fake()->createWithContent('customers.xlsx',file_get_contents($temporary));
        } finally { unlink($temporary); }
    }

    public function test_formatted_excel_template_can_preview_and_import_without_csv_conversion(): void
    {
        $values=['Khách Excel','Cá nhân','0918222333','excel@example.test','','Hà Nội','','001234567891'];
        $before=Customer::count();
        $preview=$this->withToken($this->token())->post('/api/v1/customer-import',['file'=>$this->excel($values),'confirm'=>'0'],['Accept'=>'application/json']);
        $this->assertSame(200,$preview->status(),$preview->getContent());
        $preview->assertJsonPath('data.can_import',true)->assertJsonPath('data.preview.0.row',5);
        $this->assertSame($before,Customer::count());
        $this->post('/api/v1/customer-import',['file'=>$this->excel($values),'confirm'=>'1'],['Accept'=>'application/json'])
            ->assertOk()->assertJsonPath('data.imported',1);
        $this->assertDatabaseHas('customers',['name'=>'Khách Excel','customer_type'=>'person','phone'=>'0918222333','identity_number'=>'001234567891']);
    }

    public function test_excel_rejects_formulas_numeric_identifiers_and_zip_expansion(): void
    {
        $values=['Khách Excel','Doanh nghiệp','0918222333','','','','',''];
        $before=Customer::count();
        foreach(['formula'=>'công thức','numeric'=>'định dạng văn bản','oversize'=>'vượt giới hạn'] as $mode=>$message) {
            $response=$this->withToken($this->token())->post('/api/v1/customer-import',['file'=>$this->excel($values,$mode),'confirm'=>'1'],['Accept'=>'application/json'])->assertUnprocessable();
            $this->assertStringContainsString($message,$response->json('fields.file.0'));
        }
        $this->assertSame($before,Customer::count());
    }

    public function test_blank_excel_template_and_malformed_package_do_not_create_customers(): void
    {
        $this->withToken($this->token())->post('/api/v1/customer-import',[
            'file'=>UploadedFile::fake()->createWithContent('empty.xlsx',file_get_contents(resource_path('templates/import/mau-khach-hang.xlsx'))),
        ],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/v1/customer-import',['file'=>UploadedFile::fake()->createWithContent('bad.xlsx','not an Excel file')],['Accept'=>'application/json'])->assertUnprocessable();
    }
}
