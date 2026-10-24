<?php
namespace Tests\Feature;
use App\Models\{User,Supplier,Warehouse,WarehouseLocation,Tenant};
use App\Support\ProcurementSourceImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProcurementSourceImportTest extends TestCase
{
    use RefreshDatabase;
    private User $actor;private string $token;
    protected function setUp():void{parent::setUp();$this->seed();$this->actor=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->token=app(\App\Support\JwtTokenService::class)->issue($this->actor);}
    private function preview(string $kind,array $rows,bool $technical=false){
        $labels=ProcurementSourceImport::labels($kind);if(!$technical)unset($labels['schema_version'],$labels['source_revision']);
        $path=tempnam(sys_get_temp_dir(),'procurement-test-');copy(resource_path('templates/import/'.$kind.'-v1.xlsx'),$path);
        try{$zip=new \ZipArchive;$zip->open($path);$xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';$values=[array_values($labels)];foreach($rows as $row)$values[]=array_map(fn($key)=>(string)($row[$key] ?? ''),array_keys($labels));
            foreach($values as $r=>$cells){$xml.='<row r="'.($r+1).'">';foreach($cells as $c=>$value)$xml.='<c r="'.chr(65+$c).($r+1).'" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1).'</t></is></c>';$xml.='</row>';}
            $zip->addFromString('xl/worksheets/sheet1.xml',$xml.'</sheetData></worksheet>');$zip->setMtimeName('xl/worksheets/sheet1.xml',946684800);$zip->close();
            return $this->withToken($this->token)->post('/api/v1/import-center/procurement/'.$kind.'/preview',['file'=>UploadedFile::fake()->createWithContent('mau.xlsx',file_get_contents($path))],['Accept'=>'application/json']);
        }finally{unlink($path);}
    }
    private function commit($id){return $this->withToken($this->token)->postJson('/api/v1/import-center/batches/'.$id.'/commit',['confirmed'=>true]);}
    private function warehouse():Warehouse{
        $id=$this->preview('warehouses',[['external_id'=>'KHO-EXT','name'=>'Kho nhập thử','manager_ref'=>$this->actor->email]])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$this->commit($id)->assertOk();return Warehouse::where('name','Kho nhập thử')->firstOrFail();
    }
    public function test_supplier_preview_create_update_preserve_blanks_and_repeat_safe():void{
        $before=Supplier::count();$row=['external_id'=>'NCC-01','name'=>'Nhà cung cấp thử','tax_code'=>'0312345678','phone'=>'0901234567','email'=>'ncc@example.test','payment_terms'=>'30 ngày'];
        $id=$this->preview('suppliers',[$row])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$this->assertSame($before,Supplier::count());$this->commit($id)->assertOk();$supplier=Supplier::where('email','ncc@example.test')->firstOrFail();$this->assertStringStartsWith('SUP-',$supplier->code);
        $row['name']='Tên mới';$row['phone']='';$id=$this->preview('suppliers',[$row])->assertCreated()->assertJsonPath('data.result.preview.0.action','update')->json('data.id');$this->commit($id)->assertOk();$this->assertSame('0901234567',$supplier->fresh()->phone);$this->assertSame('Tên mới',$supplier->fresh()->name);
        $this->commit($id)->assertOk()->assertJsonPath('replayed',true);$this->preview('suppliers',[$row])->assertOk()->assertJsonPath('replayed',true);$this->assertSame($before+1,Supplier::count());
    }
    public function test_supplier_duplicate_and_vietnamese_errors_block_whole_batch():void{
        $before=Supplier::count();$response=$this->preview('suppliers',[['name'=>'Hợp lệ'],['name'=>'Sai email','email'=>'not-email']])->assertCreated()->assertJsonPath('data.status','invalid');$this->assertStringContainsString('Email không đúng định dạng',$response->json('data.result.errors.0.message'));$this->commit($response->json('data.id'))->assertUnprocessable();$this->assertSame($before,Supplier::count());
        $this->preview('suppliers',[['name'=>'Một','phone'=>'0901234567'],['name'=>'Hai','phone'=>'+84 901 234 567']])->assertCreated()->assertJsonPath('data.status','invalid');
    }
    public function test_warehouse_manager_update_and_inactive_manager_after_preview():void{
        $warehouse=$this->warehouse();$this->assertSame($this->actor->id,$warehouse->manager_id);$this->assertStringStartsWith('WH-',$warehouse->code);
        $id=$this->preview('warehouses',[['external_id'=>'KHO-EXT','name'=>'Kho đổi tên','address'=>'Địa chỉ mới']])->assertCreated()->json('data.id');$this->commit($id)->assertOk();$this->assertSame($this->actor->id,$warehouse->fresh()->manager_id);
        $manager=User::where('tenant_id',$this->actor->tenant_id)->where('id','!=',$this->actor->id)->where('is_active',true)->firstOrFail();
        $id=$this->preview('warehouses',[['external_id'=>'KHO-EXT','name'=>'Không lưu','manager_ref'=>$manager->email]])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$manager->update(['is_active'=>false]);$this->commit($id)->assertUnprocessable();$this->assertSame('Kho đổi tên',$warehouse->fresh()->name);
    }
    public function test_locations_resolve_exact_parent_autocode_replay_and_never_move():void{
        $warehouse=$this->warehouse();$row=['external_id'=>'VT-01','warehouse_ref'=>'ext:KHO-EXT','name'=>'Kệ A'];$id=$this->preview('locations',[$row])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$this->commit($id)->assertOk();$location=WarehouseLocation::where('warehouse_id',$warehouse->id)->where('name','Kệ A')->firstOrFail();$this->assertStringStartsWith('LOC-',$location->code);$this->commit($id)->assertOk()->assertJsonPath('replayed',true);
        $other=Warehouse::create(['tenant_id'=>$this->actor->tenant_id,'code'=>'WH-OTHER','name'=>'Kho khác','status'=>'active']);$row['warehouse_ref']='crm:'.$other->code;$this->preview('locations',[$row])->assertCreated()->assertJsonPath('data.status','invalid');$this->assertSame($warehouse->id,$location->fresh()->warehouse_id);
        $row['warehouse_ref']='crm:'.$warehouse->code;$row['name']='Không cập nhật';$this->preview('locations',[$row])->assertCreated()->assertJsonPath('data.status','invalid');
    }
    public function test_missing_foreign_or_inactive_warehouse_blocks_and_parent_change_after_preview_blocks():void{
        $this->preview('locations',[['name'=>'Thiếu kho']])->assertCreated()->assertJsonPath('data.status','invalid');
        $tenant=Tenant::create(['code'=>'OTHER-IMPORT','name'=>'Công ty khác','status'=>'active']);$foreign=Warehouse::create(['tenant_id'=>$tenant->id,'code'=>'WH-FOREIGN','name'=>'Kho khác công ty','status'=>'active']);
        $this->preview('locations',[['warehouse_ref'=>'crm:'.$foreign->code,'name'=>'Không nhập']])->assertCreated()->assertJsonPath('data.status','invalid');
        $warehouse=$this->warehouse();$id=$this->preview('locations',[['warehouse_ref'=>'crm:'.$warehouse->code,'name'=>'Kệ chưa nhập']])->assertCreated()->json('data.id');$warehouse->update(['status'=>'inactive']);$this->commit($id)->assertUnprocessable();$this->assertDatabaseMissing('warehouse_locations',['name'=>'Kệ chưa nhập']);
    }
    public function test_supplier_manual_edit_blocks_and_business_failure_rolls_back_entire_batch():void{
        $id=$this->preview('suppliers',[['external_id'=>'NCC-01','name'=>'Ban đầu']])->assertCreated()->json('data.id');$this->commit($id)->assertOk();$supplier=Supplier::where('name','Ban đầu')->firstOrFail();
        $id=$this->preview('suppliers',[['external_id'=>'NCC-01','name'=>'Không ghi']])->assertCreated()->json('data.id');$supplier->update(['address'=>'Sửa tay']);$this->commit($id)->assertUnprocessable();
        $id=$this->preview('suppliers',[['name'=>'Dòng mới 1'],['name'=>'Dòng mới 2']])->assertCreated()->json('data.id');$before=Supplier::count();$original=app(\App\Http\Controllers\Api\V1\SupplierController::class);$fault=\Mockery::mock($original)->makePartial();$calls=0;
        $fault->shouldReceive('store')->twice()->andReturnUsing(function($request)use($original,&$calls){$response=$original->store($request);if(++$calls===2)throw new \RuntimeException('SYNTHETIC_IMPORT_FAILURE');return $response;});$this->app->instance(\App\Http\Controllers\Api\V1\SupplierController::class,$fault);
        $this->commit($id)->assertStatus(500);$this->assertSame($before,Supplier::count());$this->assertDatabaseHas('import_batches',['id'=>$id,'status'=>'ready']);
    }
    public function test_templates_have_vietnamese_names_and_scoped_dropdowns():void{
        $warehouse=$this->warehouse();$tenant=Tenant::create(['code'=>'OPTIONS-OTHER','name'=>'Khác','status'=>'active']);Warehouse::create(['tenant_id'=>$tenant->id,'code'=>'SECRET-WH-OTHER','name'=>'Không được lộ','status'=>'active']);
        foreach(['suppliers','warehouses','locations'] as $kind){$response=$this->withToken($this->token)->get('/api/v1/import-center/procurement/'.$kind.'/template')->assertOk()->assertDownload();$this->assertStringContainsString(rawurlencode('Mẫu '),$response->headers->get('Content-Disposition'));
            if($kind!=='suppliers'){$zip=new \ZipArchive;$zip->open($response->baseResponse->getFile()->getPathname());$xml=simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));$range=$kind==='warehouses'?'D2:D301':'B2:B301';$this->assertCount(1,$xml->xpath('//*[local-name()="dataValidation" and @sqref="'.$range.'"]'));$this->assertStringContainsString($kind==='warehouses'?$this->actor->email:'crm:'.$warehouse->code,$zip->getFromName('xl/worksheets/sheet100.xml'));$this->assertStringNotContainsString('SECRET-WH-OTHER',$zip->getFromName('xl/worksheets/sheet100.xml'));$zip->close();}
        }
    }
    public function test_technical_original_template_and_permission_revocation():void{
        $id=$this->preview('suppliers',[['schema_version'=>'1','source_revision'=>'1','external_id'=>'OLD-01','name'=>'Mẫu cũ'] ],true)->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');
        $permission=\App\Models\Permission::where('code','supplier.manage')->firstOrFail();foreach($this->actor->roles as $role)$role->permissions()->detach($permission->id);$this->commit($id)->assertForbidden();$this->assertDatabaseMissing('suppliers',['name'=>'Mẫu cũ']);
        $this->withToken($this->token)->get('/api/v1/import-center/procurement/suppliers/template')->assertForbidden();
    }
    public function test_warehouse_and_supplier_without_external_id_autocode_and_location_duplicates_are_scoped_to_parent():void{
        $id=$this->preview('suppliers',[['name'=>'NCC không mã ngoài']])->assertCreated()->json('data.id');$this->commit($id)->assertOk();$supplier=Supplier::where('name','NCC không mã ngoài')->firstOrFail();$this->assertStringStartsWith('SUP-',$supplier->code);$this->assertDatabaseMissing('import_record_links',['catalog'=>'suppliers','record_id'=>$supplier->id]);
        $warehouse=$this->warehouse();$id=$this->preview('locations',[['warehouse_ref'=>'crm:'.$warehouse->code,'code'=>'KE-A','name'=>'Kệ một']])->assertCreated()->json('data.id');$this->commit($id)->assertOk();
        $this->preview('locations',[['warehouse_ref'=>'crm:'.$warehouse->code,'code'=>'KE-A','name'=>'Kệ khác']])->assertCreated()->assertJsonPath('data.status','invalid');
        $id=$this->preview('warehouses',[['name'=>'Kho không mã ngoài']])->assertCreated()->json('data.id');$this->commit($id)->assertOk();$other=Warehouse::where('name','Kho không mã ngoài')->firstOrFail();$this->assertStringStartsWith('WH-',$other->code);$this->assertDatabaseMissing('import_record_links',['catalog'=>'warehouses','record_id'=>$other->id]);
        $this->travel(61)->seconds();
        $id=$this->preview('locations',[['warehouse_ref'=>'crm:'.$other->code,'code'=>'KE-A','name'=>'Kệ một']])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$this->commit($id)->assertOk();
        $this->assertSame(2,WarehouseLocation::where('code','KE-A')->count());
    }
}
