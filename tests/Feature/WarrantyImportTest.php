<?php
namespace Tests\Feature;
use App\Models\{Customer,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
class WarrantyImportTest extends TestCase
{
    use RefreshDatabase;
    private function file(string $rows):UploadedFile { return UploadedFile::fake()->createWithContent('warranty.csv',"\xEF\xBB\xBFsep=;\r\nMã khách hàng;Serial;Sản phẩm;Ngày mua;Ngày bắt đầu;Số tháng\r\n".$rows); }
    public function test_preview_confirm_replay_and_atomic_validation():void {
        $this->seed(); $user=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $token=$this->postJson('/api/v1/auth/login',['email'=>$user->email,'password'=>'Admin@123'])->json('data.access_token');
        $code=Customer::where('tenant_id',$user->tenant_id)->firstOrFail()->code;
        $row="$code;00001;Máy in;2026-10-01;2026-10-01;12\r\n";
        $this->withToken($token)->post('/api/v1/warranty-import',['file'=>$this->file($row)])->assertOk()->assertJsonPath('data.can_import',true)->assertJsonPath('data.imported',0);
        $this->assertDatabaseCount('warranty_claims',0);
        $this->withToken($token)->post('/api/v1/warranty-import',['file'=>$this->file($row),'confirm'=>'1'])->assertOk()->assertJsonPath('data.imported',1);
        $this->assertDatabaseHas('warranty_claims',['serial_number'=>'00001','product_name'=>'Máy in']);
        $this->withToken($token)->post('/api/v1/warranty-import',['file'=>$this->file($row),'confirm'=>'1'])->assertOk()->assertJsonPath('data.can_import',false);
        $this->withToken($token)->post('/api/v1/warranty-import',['file'=>$this->file("$code;00002;Máy in;;2026-10-01;12\r\nBAD;00003;Máy in;;2026-10-01;12"),'confirm'=>'1'])->assertOk()->assertJsonPath('data.imported',0);
        $this->assertDatabaseCount('warranty_claims',1);
        $this->withToken($token)->get('/api/v1/warranty-import/template')->assertOk()->assertDownload('mau-bao-hanh.xlsx');
        $this->withToken($token)->post('/api/v1/warranty-import',['file'=>$this->file("$code;00004;Máy in;;2026-10-01;12\r\n$code;00004;Máy in;;2026-10-01;12"),'confirm'=>'1'])->assertOk()->assertJsonPath('data.can_import',false);
        $this->assertDatabaseCount('warranty_claims',1);
    }
    public function test_permission_and_file_guards():void {
        $this->seed(); $this->post('/api/v1/warranty-import',['file'=>$this->file('')],['Accept'=>'application/json'])->assertUnauthorized();
        $user=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $token=$this->postJson('/api/v1/auth/login',['email'=>$user->email,'password'=>'Admin@123'])->json('data.access_token');
        $this->withToken($token)->post('/api/v1/warranty-import',['file'=>UploadedFile::fake()->createWithContent('wrong.xlsx','not an xlsx')])->assertUnprocessable();
        $this->withToken($token)->post('/api/v1/warranty-import',['file'=>$this->file('')])->assertUnprocessable();
        $this->assertDatabaseCount('warranty_claims',0);
    }
    public function test_excel_unicode_template_and_upload_round_trip():void {
        $this->seed(); $user=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $token=$this->postJson('/api/v1/auth/login',['email'=>$user->email,'password'=>'Admin@123'])->json('data.access_token');
        $this->withToken($token)->get('/api/v1/warranty-import/template')->assertOk()->assertDownload('mau-bao-hanh.xlsx');
        $decoded="sep=;\r\nMã khách hàng;Serial;Sản phẩm;Ngày mua;Ngày bắt đầu;Số tháng\r\n";
        $bytes="\xFF\xFE".mb_convert_encoding($decoded,'UTF-16LE','UTF-8');
        $code=Customer::where('tenant_id',$user->tenant_id)->firstOrFail()->code;
        $bytes.=(mb_convert_encoding("$code;000009;Máy in tiếng Việt;;2026-10-01;12\r\n",'UTF-16LE','UTF-8'));
        $file=UploadedFile::fake()->createWithContent('unicode.csv',$bytes);
        $this->withToken($token)->post('/api/v1/warranty-import',['file'=>$file,'confirm'=>'1'])->assertOk()->assertJsonPath('data.imported',1);
        $this->assertDatabaseHas('warranty_claims',['serial_number'=>'000009','product_name'=>'Máy in tiếng Việt']);
    }
    public function test_generated_excel_template_dates_and_text_serial_import():void {
        $this->seed(); $user=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $token=$this->postJson('/api/v1/auth/login',['email'=>$user->email,'password'=>'Admin@123'])->json('data.access_token');
        $code=Customer::where('tenant_id',$user->tenant_id)->firstOrFail()->code;
        $path=tempnam(sys_get_temp_dir(),'warranty-xlsx-');
        copy(resource_path('templates/import/mau-bao-hanh.xlsx'),$path);
        $zip=new \ZipArchive; $zip->open($path);
        $xml=$zip->getFromName('xl/worksheets/sheet1.xml');
        $row='<row xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" r="5">';
        foreach(['A'=>$code,'B'=>'000123','C'=>'Máy in','D'=>'05/10/2026'] as $column=>$value) $row.='<c r="'.$column.'5" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1).'</t></is></c>';
        $serial=(int)((strtotime('2026-10-20 UTC')-strtotime('1899-12-30 UTC'))/86400);
        $row.='<c r="E5"><v>'.$serial.'</v></c><c r="F5"><v>12</v></c></row>';
        // Generated template has styled empty rows; replace only row 5.
        $xml=preg_replace('~<(?:x:)?row\b[^>]*\br="5"[^>]*(?:/>|>.*?</(?:x:)?row>)~s',$row,$xml,1,$count);
        $this->assertSame(1,$count);
        $zip->addFromString('xl/worksheets/sheet1.xml',$xml); $zip->close();
        $bytes=file_get_contents($path); unlink($path);
        $this->withToken($token)->post('/api/v1/warranty-import',['file'=>UploadedFile::fake()->createWithContent('warranty.xlsx',$bytes),'confirm'=>'1'])->assertOk()->assertJsonPath('data.imported',1);
        $claim=\App\Models\WarrantyClaim::where('serial_number','000123')->firstOrFail();
        $this->assertSame('2026-10-05',$claim->purchase_date->format('Y-m-d'));
        $this->assertSame('2026-10-20',$claim->warranty_start_at->format('Y-m-d'));
    }
}
