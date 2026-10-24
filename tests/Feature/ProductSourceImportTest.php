<?php
namespace Tests\Feature;
use App\Models\{User,ProductCategory,ProductBrand,Sku,Product};
use App\Support\ProductSourceImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductSourceImportTest extends TestCase
{
    use RefreshDatabase;
    private string $token;private User $actor;
    protected function setUp():void {parent::setUp();$this->seed();$this->actor=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->token=app(\App\Support\JwtTokenService::class)->issue($this->actor);}
    private function preview(string $kind,array $rows,bool $technical=false){
        $labels=$kind==='skus'?ProductSourceImport::SKU:ProductSourceImport::BASIC;if(!$technical)unset($labels['schema_version'],$labels['source_revision']);
        $path=tempnam(sys_get_temp_dir(),'product-test-');copy(resource_path('templates/import/'.$kind.'-v1.xlsx'),$path);
        try{$z=new \ZipArchive;$z->open($path);$xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            $values=[array_values($labels)];foreach($rows as $row)$values[]=array_map(fn($key)=>(string)($row[$key] ?? ''),array_keys($labels));
            foreach($values as $r=>$cells){$xml.='<row r="'.($r+1).'">';foreach($cells as $c=>$v)$xml.='<c r="'.chr(65+$c).($r+1).'" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1).'</t></is></c>';$xml.='</row>';}
            $z->addFromString('xl/worksheets/sheet1.xml',$xml.'</sheetData></worksheet>');$z->close();
            return $this->withToken($this->token)->post('/api/v1/import-center/products/'.$kind.'/preview',['file'=>UploadedFile::fake()->createWithContent('mau.xlsx',file_get_contents($path))],['Accept'=>'application/json']);
        }finally{unlink($path);}
    }
    private function commit($id){return $this->withToken($this->token)->postJson('/api/v1/import-center/batches/'.$id.'/commit',['confirmed'=>true]);}
    private function parents():void {foreach(['categories'=>'NHOM-01','brands'=>'HANG-01'] as $kind=>$external){$id=$this->preview($kind,[['external_id'=>$external,'name'=>'Thử '.$kind,'status'=>'Hoạt động']])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$this->commit($id)->assertOk();}}
    private function sku(array $extra=[]):array {return array_replace(['external_id'=>'SP-01','name'=>'Mã hàng thử','product_name'=>'Sản phẩm thử','sku_code'=>'SKU-TRIAL','product_code'=>'PROD-TRIAL','category_ref'=>'ext:NHOM-01','brand_ref'=>'ext:HANG-01','unit'=>'cái','min_stock'=>'1','max_stock'=>'10','sale_price'=>'1000000','cost_price'=>'800000','status'=>'Hoạt động'],$extra);}
    public function test_parent_catalogs_preview_then_create_and_replay_without_duplicates():void {
        $before=ProductCategory::count();$id=$this->preview('categories',[['external_id'=>'NHOM-01','name'=>'Nhóm thử']])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $this->assertSame($before,ProductCategory::count());$this->commit($id)->assertOk();$this->commit($id)->assertOk()->assertJsonPath('replayed',true);$this->assertSame($before+1,ProductCategory::count());
        $this->preview('categories',[['external_id'=>'NHOM-01','name'=>'Không đổi tên']])->assertCreated()->assertJsonPath('data.status','invalid');
        $this->preview('categories',[['name'=>'Nhóm thử']])->assertCreated()->assertJsonPath('data.status','invalid');
    }
    public function test_one_invalid_row_blocks_entire_catalog_and_errors_are_vietnamese():void {
        $before=ProductBrand::count();$res=$this->preview('brands',[['name'=>'Hãng hợp lệ'],['name'=>'','status'=>'wrong']])->assertCreated()->assertJsonPath('data.status','invalid');
        $this->assertStringContainsString('Tên',$res->json('data.result.errors.0.message'));$this->assertStringNotContainsString('field is required',$res->getContent());
        $this->commit($res->json('data.id'))->assertUnprocessable();$this->assertSame($before,ProductBrand::count());
    }
    public function test_sku_resolves_parent_imports_updates_preserving_blanks_and_blocks_manual_conflict():void {
        $this->parents();$before=Sku::count();$id=$this->preview('skus',[$this->sku()])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$this->assertSame($before,Sku::count());$this->commit($id)->assertOk();
        $sku=Sku::where('sku_code','SKU-TRIAL')->firstOrFail();$this->assertSame('Thử categories',$sku->product->category->name);$this->assertSame('Thử brands',$sku->product->brand->name);
        $id=$this->preview('skus',[$this->sku(['sale_price'=>'1200000','cost_price'=>'','description'=>'Mới'])])->assertCreated()->assertJsonPath('data.result.preview.0.action','update')->json('data.id');$this->commit($id)->assertOk();
        $this->assertSame(1200000.0,(float)$sku->fresh()->sale_price);$this->assertSame(800000.0,(float)$sku->fresh()->cost_price);$this->assertSame($before+1,Sku::count());
        $id=$this->preview('skus',[$this->sku(['sale_price'=>'1300000'])])->assertCreated()->json('data.id');$sku->update(['name'=>'Sửa tay']);$this->commit($id)->assertUnprocessable();
        $this->assertSame('Sửa tay',$sku->fresh()->name);
    }
    public function test_missing_inactive_parent_duplicate_codes_and_invalid_stock_are_blocked():void {
        $this->preview('skus',[$this->sku()])->assertCreated()->assertJsonPath('data.status','invalid');$this->parents();
        $this->preview('skus',[$this->sku(['max_stock'=>'0.5'])])->assertCreated()->assertJsonPath('data.status','invalid');
        $this->preview('skus',[$this->sku(),$this->sku(['external_id'=>'SP-02'])])->assertCreated()->assertJsonPath('data.status','invalid');
        ProductBrand::where('name','Thử brands')->update(['status'=>'inactive']);$this->preview('skus',[$this->sku()])->assertCreated()->assertJsonPath('data.status','invalid');
    }
    public function test_business_failure_rolls_back_product_sku_mapping_and_batch():void {
        $this->parents();$id=$this->preview('skus',[$this->sku()])->assertCreated()->json('data.id');$before=Sku::count();$products=Product::count();
        $original=app(\App\Http\Controllers\Api\V1\SkuController::class);$fault=\Mockery::mock($original)->makePartial();$fault->shouldReceive('store')->once()->andReturnUsing(function($request)use($original){$original->store($request);throw new \RuntimeException('SYNTHETIC_PRODUCT_FAIL');});$this->app->instance(\App\Http\Controllers\Api\V1\SkuController::class,$fault);
        $this->commit($id)->assertStatus(500);$this->assertSame($before,Sku::count());$this->assertSame($products,Product::count());$this->assertDatabaseMissing('import_record_links',['catalog'=>'skus']);$this->assertDatabaseHas('import_batches',['id'=>$id,'status'=>'ready']);
    }
    public function test_downloads_are_vietnamese_and_sku_has_dynamic_parent_choices():void {
        $this->parents();foreach(['categories','brands','skus'] as $kind){$response=$this->withToken($this->token)->get('/api/v1/import-center/products/'.$kind.'/template')->assertOk()->assertDownload();$this->assertStringContainsString(rawurlencode('Mẫu '),$response->headers->get('Content-Disposition'));
            if($kind==='skus'){
                $zip=new \ZipArchive;$zip->open($response->baseResponse->getFile()->getPathname());
                $sheet=simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
                foreach(['F2:F301','G2:G301'] as $range)$this->assertCount(1,$sheet->xpath('//*[local-name()="dataValidation" and @sqref="'.$range.'"]'));
                $this->assertStringContainsString('crm:Thử categories',$zip->getFromName('xl/worksheets/sheet100.xml'));$zip->close();
            }
        }
        $this->withHeader('Authorization','')->get('/api/v1/import-center/products/skus/template')->assertUnauthorized();
    }
    public function test_cost_permission_is_rechecked_on_confirm_and_template_download():void {
        $this->parents();$id=$this->preview('skus',[$this->sku()])->assertCreated()->json('data.id');
        $permission=\App\Models\Permission::where('code','master.cost.view')->firstOrFail();foreach($this->actor->roles as $role)$role->permissions()->detach($permission->id);
        $this->commit($id)->assertForbidden();$this->withToken($this->token)->get('/api/v1/import-center/products/skus/template')->assertForbidden();
        $this->preview('skus',[$this->sku()])->assertForbidden();$this->assertDatabaseMissing('skus',['sku_code'=>'SKU-TRIAL']);
        $this->withToken($this->token)->get('/api/v1/import-center/products/categories/template')->assertOk();
    }
    public function test_exact_crm_parent_name_works_and_old_technical_template_is_accepted():void {
        $this->parents();$id=$this->preview('skus',[$this->sku(['schema_version'=>'1','source_revision'=>'1','category_ref'=>'crm:Thử categories','brand_ref'=>'crm:Thử brands'])],true)->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $this->commit($id)->assertOk();$sku=Sku::where('sku_code','SKU-TRIAL')->firstOrFail();$this->assertSame('Thử categories',$sku->product->category->name);
        $this->commit($id)->assertOk()->assertJsonPath('replayed',true);
    }
    public function test_parent_inactive_after_preview_blocks_commit_and_missing_external_id_still_autocodes():void {
        $this->parents();$id=$this->preview('skus',[$this->sku(['external_id'=>'','sku_code'=>'','product_code'=>''])])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $this->commit($id)->assertOk();$sku=Sku::where('name','Mã hàng thử')->firstOrFail();$this->assertNotEmpty($sku->sku_code);$this->assertNotEmpty($sku->product->code);$this->assertDatabaseMissing('import_record_links',['catalog'=>'skus']);
        $id=$this->preview('skus',[$this->sku(['external_id'=>'SP-02','name'=>'Khác'])])->assertCreated()->json('data.id');ProductCategory::where('name','Thử categories')->update(['status'=>'inactive']);
        $this->commit($id)->assertUnprocessable();$this->assertDatabaseMissing('skus',['sku_code'=>'SKU-TRIAL']);
    }
}
