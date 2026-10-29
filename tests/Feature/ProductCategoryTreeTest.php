<?php
namespace Tests\Feature;
use App\Models\{ProductCategory,Tenant};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
class ProductCategoryTreeTest extends TestCase
{
    use RefreshDatabase;
    private function login():string {
        $this->seed();return $this->postJson('/api/v1/auth/login',['email'=>'admin@vk-kpi.local','password'=>'Admin@123'])->json('data.access_token');
    }
    public function test_two_levels_paths_and_guarded_changes():void {
        $token=$this->login();
        $parent=$this->withToken($token)->postJson('/api/v1/product-categories',['name'=>'Ẩm thực'])->assertCreated()->json('data.id');
        $child=$this->postJson('/api/v1/product-categories',['name'=>'Nhà hàng','parent_id'=>$parent])->assertCreated()->json('data.id');
        $serviceId=ProductCategory::findOrFail($child)->service_interest_id;
        $this->assertDatabaseHas('service_interests',['id'=>$serviceId,'name'=>'Nhà hàng','is_active'=>true]);
        $this->putJson('/api/v1/product-categories/'.$child,['name'=>'Nhà hàng mới','status'=>'active','parent_id'=>$parent])->assertOk();
        $this->assertSame($serviceId,ProductCategory::findOrFail($child)->service_interest_id);
        $this->assertDatabaseHas('service_interests',['id'=>$serviceId,'name'=>'Nhà hàng mới']);
        $this->postJson('/api/v1/product-categories',['name'=>'Cấp 3','parent_id'=>$child])->assertUnprocessable();
        $this->putJson('/api/v1/product-categories/'.$parent,['name'=>'Ẩm thực','status'=>'active','parent_id'=>$child])->assertUnprocessable();
        $this->deleteJson('/api/v1/product-categories/'.$parent)->assertUnprocessable();
        $this->putJson('/api/v1/product-categories/'.$parent,['name'=>'Ẩm thực','status'=>'inactive'])->assertUnprocessable();
        $paths=collect($this->getJson('/api/v1/product-categories')->assertOk()->json('data'));
        $this->assertSame('Ẩm thực / Nhà hàng mới',$paths->firstWhere('id',$child)['path']);
        $other=Tenant::create(['code'=>'FOREIGN-TREE','name'=>'Other','status'=>'active']);
        $foreign=ProductCategory::create(['tenant_id'=>$other->id,'name'=>'Other','status'=>'active']);
        $this->postJson('/api/v1/product-categories',['name'=>'Wrong','parent_id'=>$foreign->id])->assertUnprocessable();
    }
    public function test_original_classification_file_preview_then_commit_and_repeat():void {
        $token=$this->login();
        $path=tempnam(sys_get_temp_dir(),'category-tree-test');$zip=new \ZipArchive;$zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Danh mục" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="4"><c r="E4" t="inlineStr"><is><t>NHÓM SẢN PHẨM CẤP 1</t></is></c><c r="F4" t="inlineStr"><is><t>NHÓM SẢN PHẨM CẤP 2</t></is></c></row><row r="5"><c r="E5" t="inlineStr"><is><t>Ẩm thực</t></is></c><c r="F5" t="inlineStr"><is><t>Nhà hàng</t></is></c></row></sheetData></worksheet>');$zip->close();
        try{
            $file=new UploadedFile($path,'phan-loai.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
            $response=$this->withToken($token)->post('/api/v1/product-categories/import-tree',['file'=>$file],['Accept'=>'application/json']);
            $this->assertSame(200,$response->status(),$response->getContent());
            $this->assertSame(2,$response->json('data.create_count'));
            $this->assertDatabaseMissing('product_categories',['name'=>'Nhà hàng']);
            $this->postJson('/api/v1/product-categories/import-tree',['token'=>$response->json('data.token')])->assertOk()->assertJsonPath('data.created',2);
            $parent=ProductCategory::where('name','Ẩm thực')->firstOrFail();
            $this->assertDatabaseHas('product_categories',['name'=>'Nhà hàng','parent_id'=>$parent->id]);
            $again=$this->post('/api/v1/product-categories/import-tree',['file'=>$file],['Accept'=>'application/json'])->assertOk();
            $this->assertSame(0,$again->json('data.create_count'));
        }finally{unlink($path);}
    }
}
