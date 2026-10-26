<?php
namespace Tests\Feature;

use App\Models\{User,Customer};
use App\Support\ImportTemplateOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportTemplateOptionsTest extends TestCase
{
    use RefreshDatabase;
    public function test_download_lists_preserve_template_and_use_only_active_current_company_records():void
    {
        $this->seed();$actor=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $customer=Customer::where('tenant_id',$actor->tenant_id)->where('customer_type','organization')->firstOrFail();$customer->update(['status'=>'active']);
        $inactive=Customer::create(['tenant_id'=>$actor->tenant_id,'code'=>'CUS-HIDDEN-OPTIONS','name'=>'Không được chọn','customer_type'=>'organization','status'=>'inactive']);
        foreach(['customers'=>['khach-hang','N2:N301'],'contacts'=>['nguoi-lien-he','B2:B301'],'leads'=>['khach-tiem-nang','G2:G301']] as $kind=>[$name,$range]){
            $template=resource_path('templates/import/'.$name.'-theo-nguon-v1.xlsx');$before=hash_file('sha256',$template);
            $path=app(ImportTemplateOptions::class)->build($template,$actor,$kind);
            try{
                $zip=new \ZipArchive;$this->assertTrue($zip->open($path));
                $book=simplexml_load_string($zip->getFromName('xl/workbook.xml'));
                $sheets=$book->xpath('//*[local-name()="sheet" and @name="Danh mục tham chiếu"]');$this->assertCount(1,$sheets);
                $rels=simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
                $this->assertCount(1,$rels->xpath('//*[local-name()="Relationship" and @Id="rIdImportOptions"]'));
                $xml=simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
                $validation=$xml->xpath('//*[local-name()="dataValidation" and @sqref="'.$range.'"]');$this->assertCount(1,$validation);
                $formula=$validation[0]->xpath('./*[local-name()="formula1"]');$this->assertStringContainsString('INDIRECT(', (string)$formula[0]);
                $reference=$zip->getFromName('xl/worksheets/sheet100.xml');$this->assertStringContainsString($actor->email,$reference);
                $this->assertStringNotContainsString($inactive->code,$reference);
                if($kind!=='customers')$this->assertStringContainsString('crm:'.$customer->code,$reference);
                $this->assertSame($before,hash_file('sha256',$template));
                $original=new \ZipArchive;$original->open($template);
                $this->assertSame($original->getFromName('xl/styles.xml'),$zip->getFromName('xl/styles.xml'));
                if($kind==='customers'){
                    // The customer guide now adds one group instruction row. Existing cells/styles must remain intact.
                    $oldGuide=new \DOMDocument;$oldGuide->loadXML($original->getFromName('xl/worksheets/sheet2.xml'));
                    $newGuide=new \DOMDocument;$newGuide->loadXML($zip->getFromName('xl/worksheets/sheet2.xml'));
                    $oldXp=new \DOMXPath($oldGuide);$newXp=new \DOMXPath($newGuide);
                    foreach($oldXp->query('//*[local-name()="c"]') as $cell){
                        $same=$newXp->query('//*[local-name()="c" and @r="'.$cell->getAttribute('r').'"]')->item(0);
                        $this->assertNotNull($same);
                        $this->assertSame($cell->textContent,$same->textContent);
                        $this->assertSame($cell->getAttribute('s'),$same->getAttribute('s'));
                    }
                    $this->assertSame('Phân nhóm khách hàng',$newXp->query('//*[local-name()="c" and @r="A8"]')->item(0)->textContent);
                }else $this->assertSame($original->getFromName('xl/worksheets/sheet2.xml'),$zip->getFromName('xl/worksheets/sheet2.xml'));
                $original->close();
            }finally{$zip->close();unlink($path);}
        }
    }
}
