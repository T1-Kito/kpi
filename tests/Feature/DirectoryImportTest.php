<?php
namespace Tests\Feature;
use App\Models\{Customer,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
class DirectoryImportTest extends TestCase
{
    use RefreshDatabase;
    private function file(string $kind,array $rows):UploadedFile {
        $path=tempnam(sys_get_temp_dir(),'directory-');copy(resource_path('templates/import/'.$kind.'.xlsx'),$path);
        $zip=new \ZipArchive;$zip->open($path);$xml=$zip->getFromName('xl/worksheets/sheet1.xml');
        foreach($rows as $index=>$values) {
            $line=5+$index;$row='<row xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" r="'.$line.'">';
            foreach($values as $col=>$v) $row.='<c r="'.chr(65+$col).$line.'" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1).'</t></is></c>';
            $row.='</row>';$xml=preg_replace('~<(?:x:)?row\b[^>]*\br="'.$line.'"[^>]*(?:/>|>.*?</(?:x:)?row>)~s',$row,$xml,1);
        }
        $zip->addFromString('xl/worksheets/sheet1.xml',$xml);$zip->close();$bytes=file_get_contents($path);unlink($path);
        return UploadedFile::fake()->createWithContent('directory.xlsx',$bytes);
    }
    private function token():string { $this->seed();return $this->postJson('/api/v1/auth/login',['email'=>'admin@vk-kpi.local','password'=>'Admin@123'])->json('data.access_token'); }
    public function test_contact_preview_import_replay_and_customer_guard():void {
        $token=$this->token();$actor=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $c=Customer::where('tenant_id',$actor->tenant_id)->where('customer_type','organization')->firstOrFail();$before=$c->contacts()->count();
        $rows=[[$c->code,'Người nhập Excel','0900123456','excel.contact@example.com','Kế toán']];
        $this->withToken($token)->post('/api/v1/directory-import/contacts',['file'=>$this->file('contacts',$rows)])->assertOk()->assertJsonPath('data.can_import',true)->assertJsonPath('data.imported',0);
        $this->assertSame($before,$c->contacts()->count());
        $this->withToken($token)->post('/api/v1/directory-import/contacts',['file'=>$this->file('contacts',$rows),'confirm'=>'1'])->assertOk()->assertJsonPath('data.imported',1);
        $this->assertDatabaseHas('customer_contacts',['customer_id'=>$c->id,'phone'=>'0900123456']);
        $this->withToken($token)->post('/api/v1/directory-import/contacts',['file'=>$this->file('contacts',$rows),'confirm'=>'1'])->assertOk()->assertJsonPath('data.can_import',false);
        $this->withToken($token)->post('/api/v1/directory-import/contacts',['file'=>$this->file('contacts',[['UNKNOWN','Không lưu','0900999999','','']]),'confirm'=>'1'])->assertOk()->assertJsonPath('data.imported',0);
    }
    public function test_lead_preview_import_duplicates_and_invalid_file():void {
        $token=$this->token();$rows=[['Khách Excel','0900444555','lead.excel@example.com','Hội chợ','CT-001']];
        $before=\App\Models\Lead::count();
        $this->withToken($token)->post('/api/v1/directory-import/leads',['file'=>$this->file('leads',$rows)])->assertOk()->assertJsonPath('data.can_import',true);
        $this->assertSame($before,\App\Models\Lead::count());
        $this->withToken($token)->post('/api/v1/directory-import/leads',['file'=>$this->file('leads',$rows),'confirm'=>'1'])->assertOk()->assertJsonPath('data.imported',1);
        $this->assertDatabaseHas('leads',['phone'=>'0900444555','assigned_to'=>User::where('email','admin@vk-kpi.local')->value('id')]);
        $this->withToken($token)->post('/api/v1/directory-import/leads',['file'=>$this->file('leads',$rows),'confirm'=>'1'])->assertOk()->assertJsonPath('data.can_import',false);
        $this->withToken($token)->post('/api/v1/directory-import/leads',['file'=>$this->file('leads',[['A','0900111000','','',''],['B','0900111000','','','']]),'confirm'=>'1'])->assertOk()->assertJsonPath('data.imported',0);
        $this->assertSame($before+1,\App\Models\Lead::count());
        $this->withToken($token)->get('/api/v1/directory-import/leads/template')->assertOk()->assertDownload('mau-khach-tiem-nang.xlsx');
    }
    public function test_unprivileged_requests_and_foreign_customer_do_not_write():void {
        $token=$this->token(); $actor=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $other=\App\Models\Tenant::create(['code'=>'IMPORT-OTHER','name'=>'Other tenant','status'=>'active']);
        $foreign=Customer::create(['tenant_id'=>$other->id,'code'=>'FOREIGN-IMPORT','name'=>'Foreign','customer_type'=>'organization','status'=>'active']);
        $file=$this->file('contacts',[[$foreign->code,'Hidden','0900777888','','']]);
        $this->withToken($token)->post('/api/v1/directory-import/contacts',['file'=>$file,'confirm'=>'1'])->assertOk()->assertJsonPath('data.can_import',false)->assertJsonPath('data.imported',0);
        $this->assertDatabaseMissing('customer_contacts',['phone'=>'0900777888']);
        $this->withHeader('Authorization','')->getJson('/api/v1/directory-import/contacts/template')->assertUnauthorized();
    }
}
