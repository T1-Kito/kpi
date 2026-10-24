<?php
// Isolated SQL Server business trial. Never uses .env's database or changes it.
require __DIR__.'/../vendor/autoload.php';
$pdo=new PDO('sqlsrv:Server=lpc:localhost\\MSSQLSERVER01;Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=8',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT,60);
$name='VKCRM_CustomerImport_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!preg_match('/^VKCRM_CustomerImport_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/D',$name))throw new RuntimeException('Unsafe fixture');
$pdo->exec('CREATE DATABASE ['.$name.']');$pdo->exec('USE ['.$name.']');
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlsrv','database.connections.sqlsrv'=>['driver'=>'sqlsrv','host'=>'lpc:localhost\\MSSQLSERVER01','database'=>$name,'username'=>null,'password'=>null,'charset'=>'utf8','prefix'=>'','prefix_indexes'=>true,'restrict_foreign_key_deletes'=>true,'encrypt'=>'yes','trust_server_certificate'=>true]]);
Illuminate\Support\Facades\DB::purge('sqlsrv');$db=Illuminate\Support\Facades\DB::connection('sqlsrv');$db->setPdo($pdo);
echo 'Synthetic database retained: '.$name.PHP_EOL;
if(Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'sqlsrv','--force'=>true])!==0)throw new RuntimeException(Illuminate\Support\Facades\Artisan::output());
echo "PASS complete schema migration\n";
Illuminate\Support\Facades\Artisan::call('db:seed',['--database'=>'sqlsrv','--force'=>true]);
$actor=App\Models\User::where('email','admin@vk-kpi.local')->firstOrFail();
$source=$db->table('import_sources')->insertGetId(['tenant_id'=>$actor->tenant_id,'code'=>'SYNTHETIC','name'=>'Nguồn thử SQL','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
$db->table('import_source_users')->insert(['source_id'=>$source,'user_id'=>$actor->id]);
$controller=app(App\Http\Controllers\Api\V1\ImportBatchController::class);
$check=function($ok,$message){if(!$ok)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message.PHP_EOL;};
$preview=function(int $revision,string $customerName,string $phone='0900123987',string $catalog='customers-v1')use($actor,$source,$controller){
    $isContact=$catalog==='contacts-v1';$isLead=$catalog==='leads-v1';
    $path=tempnam(sys_get_temp_dir(),'sqlimport-');copy(resource_path('templates/import/'.($isLead?'khach-tiem-nang':($isContact?'nguoi-lien-he':'khach-hang')).'-theo-nguon-v1.xlsx'),$path);
    try{
        $zip=new ZipArchive;$zip->open($path);$xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $headers=$isContact?array_keys(App\Support\ContactSourceImport::LABELS):array_keys(App\Support\CustomerSourceImport::LABELS);
        $data=$isContact?['1','CONTACT-SQL-1',(string)$revision,'ext:sql-ext-1',$customerName,$phone,'sql-contact@example.test','Kinh doanh']:['1','SQL-EXT-1',(string)$revision,$customerName,'organization','','',$phone,'sqlimport@example.test','Hà Nội','Hóa đơn SQL','Đại diện SQL','Giám đốc','1000000','active',''];
        if($isLead){$headers=array_keys(App\Support\LeadSourceImport::LABELS);$data=['1','LEAD-SQL-1',(string)$revision,$customerName,$phone,'sql-lead@example.test','Hội chợ','SQL-FAIR'];}
        foreach([$headers,$data] as $i=>$values){
            $xml.='<row r="'.($i+1).'">';foreach($values as $j=>$v)$xml.='<c r="'.chr(65+$j).($i+1).'" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1).'</t></is></c>';$xml.='</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml',$xml.'</sheetData></worksheet>');$zip->close();
        $request=Illuminate\Http\Request::create('/trial','POST',['source_id'=>$source,'catalog'=>$catalog],[],['file'=>new Illuminate\Http\UploadedFile($path,'trial.xlsx',null,null,true)]);
        $request->setUserResolver(fn()=>$actor);return $controller->preview($request)->getData(true)['data'];
    }finally{if(is_file($path))unlink($path);}
};
$commit=function($id)use($controller,$actor){$r=Illuminate\Http\Request::create('/trial','POST',['confirmed'=>true]);$r->setUserResolver(fn()=>$actor);return $controller->commit($r,$id)->getData(true)['data'];};
$before=App\Models\Customer::count();$first=$preview(1,'Khách nguồn SQL');
$check($first['status']==='ready' && App\Models\Customer::count()===$before,'preview does not write customers');
$commit($first['id']);$check(App\Models\Customer::count()===$before+1,'create customer and source link');
$update=$preview(2,'Khách SQL cập nhật','');$check($update['result']['preview'][0]['action']==='update','before-after update plan');
$commit($update['id']);$customer=App\Models\Customer::where('email','sqlimport@example.test')->firstOrFail();
$check($customer->name==='Khách SQL cập nhật' && $customer->phone==='0900123987' && App\Models\Customer::count()===$before+1,'update preserves blank phone and customer count');
$commit($update['id']);$check(App\Models\Customer::count()===$before+1,'repeat commit is idempotent');
$stale=$preview(1,'Dữ liệu cũ');$check($stale['status']==='invalid','old revision rejected');
$pending=$preview(3,'Không được ghi');$customer->update(['address'=>'Sửa thủ công']);
$blocked=false;try{$commit($pending['id']);}catch(Illuminate\Validation\ValidationException){$blocked=true;}
$check($blocked && $customer->fresh()->name==='Khách SQL cập nhật' && (int)$db->table('import_record_links')->where('record_id',$customer->id)->value('source_revision')===2,'manual change after preview blocks commit and mapping update');
$contactBefore=App\Models\CustomerContact::count();$first=$preview(1,'Liên hệ SQL','0900555666','contacts-v1');
$check($first['status']==='ready' && App\Models\CustomerContact::count()===$contactBefore,'contact preview resolves same-source external parent without writes');
$commit($first['id']);$contact=App\Models\CustomerContact::where('email','sql-contact@example.test')->firstOrFail();
$check($contact->customer_id===$customer->id,'contact created under exact parent');
$update=$preview(2,'Liên hệ đổi tên','','contacts-v1');$commit($update['id']);
$check($contact->fresh()->name==='Liên hệ đổi tên' && $contact->fresh()->phone==='0900555666' && App\Models\CustomerContact::count()===$contactBefore+1,'contact update preserves blank phone and count');
$commit($update['id']);$check(App\Models\CustomerContact::count()===$contactBefore+1,'contact repeat commit safe');
$pending=$preview(3,'Không ghi liên hệ','','contacts-v1');$contact->update(['position'=>'Sửa tay SQL']);$blocked=false;
try{$commit($pending['id']);}catch(Illuminate\Validation\ValidationException){$blocked=true;}
$check($blocked && $contact->fresh()->name==='Liên hệ đổi tên','manual contact change after preview blocks commit');
echo "Synthetic local SQL only; no browser UAT, concurrency certification or client backup-rights certification.\n";
$leadBefore=App\Models\Lead::count();$first=$preview(1,'Khách tiềm năng SQL','0900666777','leads-v1');
$check($first['status']==='ready' && App\Models\Lead::count()===$leadBefore,'lead preview does not write');
$commit($first['id']);$lead=App\Models\Lead::where('email','sql-lead@example.test')->firstOrFail();
$check($lead->assigned_to===$actor->id && App\Models\Task::where('source_type','Lead')->where('source_id',$lead->id)->count()===1,'lead creates owner and one follow-up task');
$commit($first['id']);$check(App\Models\Lead::count()===$leadBefore+1 && App\Models\Task::where('source_type','Lead')->where('source_id',$lead->id)->count()===1,'lead replay does not duplicate task');
$invalid=$preview(2,'Không sửa khách cũ','0900666777','leads-v1');$check($invalid['status']==='invalid' && $lead->fresh()->name==='Khách tiềm năng SQL','lead Excel update rejected');

$productPreview=function(string $kind,array $values)use($actor,$source,$controller){
    $labels=in_array($kind,['positions','departments','users'],true)?App\Support\OrganizationSourceImport::labels($kind):(in_array($kind,['suppliers','warehouses','locations'],true)?App\Support\ProcurementSourceImport::labels($kind):($kind==='skus'?App\Support\ProductSourceImport::SKU:App\Support\ProductSourceImport::BASIC));unset($labels['schema_version'],$labels['source_revision']);
    $path=tempnam(sys_get_temp_dir(),'sql-product-');copy(resource_path('templates/import/'.$kind.'-v1.xlsx'),$path);
    try{$zip=new ZipArchive;$zip->open($path);$xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach([array_values($labels),array_map(fn($key)=>(string)($values[$key] ?? ''),array_keys($labels))] as $r=>$cells){$xml.='<row r="'.($r+1).'">';foreach($cells as $c=>$v)$xml.='<c r="'.chr(65+$c).($r+1).'" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1).'</t></is></c>';$xml.='</row>';}
        $zip->addFromString('xl/worksheets/sheet1.xml',$xml.'</sheetData></worksheet>');$zip->close();
        $request=Illuminate\Http\Request::create('/internal/trial','POST',['source_id'=>$source,'catalog'=>$kind.'-v1'],[],['file'=>new Illuminate\Http\UploadedFile($path,'thu.xlsx',null,null,true)]);$request->setUserResolver(fn()=>$actor);
        return $controller->preview($request)->getData(true)['data'];
    }finally{unlink($path);}
};
foreach(['categories'=>'CAT-SQL','brands'=>'BRAND-SQL'] as $kind=>$external){$batch=$productPreview($kind,['external_id'=>$external,'name'=>'SQL '.$kind]);$check($batch['status']==='ready','product parent preview '.$kind);$commit($batch['id']);}
$skuInput=['external_id'=>'SKU-EXT-SQL','name'=>'Sản phẩm nhập SQL','sku_code'=>'SKU-IMPORT-SQL','category_ref'=>'ext:CAT-SQL','brand_ref'=>'ext:BRAND-SQL','unit'=>'cái','min_stock'=>1,'max_stock'=>10,'sale_price'=>1000000,'cost_price'=>800000];
$beforeSku=App\Models\Sku::count();$batch=$productPreview('skus',$skuInput);$check($batch['status']==='ready' && App\Models\Sku::count()===$beforeSku,'SKU preview without business writes');$commit($batch['id']);
$sku=App\Models\Sku::where('sku_code','SKU-IMPORT-SQL')->firstOrFail();$check($sku->product->category->name==='SQL categories' && $sku->product->brand->name==='SQL brands','SKU references exact imported parents');
$skuInput['sale_price']=1200000;$skuInput['cost_price']='';$batch=$productPreview('skus',$skuInput);$check($batch['status']==='ready','SKU update preview');$commit($batch['id']);$check((float)$sku->fresh()->sale_price===1200000.0 && (float)$sku->fresh()->cost_price===800000.0,'SKU update preserves blank cost');
$commit($batch['id']);$check(App\Models\Sku::count()===$beforeSku+1,'SKU replay does not duplicate');

$supplierInput=['external_id'=>'NCC-SQL','name'=>'Nhà cung cấp SQL','phone'=>'0901888999','email'=>'supplier-sql@example.test','payment_terms'=>'30 ngày'];
$supplierCount=App\Models\Supplier::count();$batch=$productPreview('suppliers',$supplierInput);$check($batch['status']==='ready' && App\Models\Supplier::count()===$supplierCount,'supplier preview without writes');$commit($batch['id']);
$supplier=App\Models\Supplier::where('email','supplier-sql@example.test')->firstOrFail();$supplierInput['name']='NCC SQL đổi tên';$supplierInput['phone']='';$batch=$productPreview('suppliers',$supplierInput);$check($batch['status']==='ready','supplier update preview');$commit($batch['id']);$check($supplier->fresh()->phone==='0901888999' && $supplier->fresh()->name==='NCC SQL đổi tên','supplier blank preserves phone');
$warehouseInput=['external_id'=>'KHO-SQL','name'=>'Kho nhập SQL','manager_ref'=>$actor->email];$batch=$productPreview('warehouses',$warehouseInput);$check($batch['status']==='ready','warehouse manager resolves');$commit($batch['id']);$warehouse=App\Models\Warehouse::where('name','Kho nhập SQL')->firstOrFail();
$check($warehouse->manager_id===$actor->id,'warehouse assigns exact active manager');$warehouseInput['address']='Địa chỉ SQL';$warehouseInput['manager_ref']='';$batch=$productPreview('warehouses',$warehouseInput);$commit($batch['id']);$check($warehouse->fresh()->manager_id===$actor->id,'warehouse blank preserves manager');
$locationInput=['external_id'=>'VT-SQL','warehouse_ref'=>'ext:KHO-SQL','name'=>'Kệ SQL'];$batch=$productPreview('locations',$locationInput);$check($batch['status']==='ready','location resolves same-source warehouse');$commit($batch['id']);$location=App\Models\WarehouseLocation::where('warehouse_id',$warehouse->id)->where('name','Kệ SQL')->firstOrFail();$check(str_starts_with($location->code,'LOC-'),'location auto code');$commit($batch['id']);$check(App\Models\WarehouseLocation::where('warehouse_id',$warehouse->id)->where('name','Kệ SQL')->count()===1,'location replay safe');
$locationInput['name']='Không cập nhật';$batch=$productPreview('locations',$locationInput);$check($batch['status']==='invalid' && $location->fresh()->name==='Kệ SQL','location Excel update blocked');
$batch=$productPreview('positions',['external_id'=>'CV-SQL','name'=>'Chức vụ SQL']);$check($batch['status']==='ready','position preview');$commit($batch['id']);
$position=App\Models\Position::where('name','Chức vụ SQL')->firstOrFail();$check(str_starts_with($position->code,'POS-'),'position auto code');
$batch=$productPreview('departments',['external_id'=>'PB-SQL','name'=>'Phòng ban SQL','manager_ref'=>$actor->email]);$check($batch['status']==='ready','department resolves manager');$commit($batch['id']);
$department=App\Models\Department::where('name','Phòng ban SQL')->firstOrFail();$check($department->manager_id===$actor->id,'department exact manager');
$batch=$productPreview('departments',['external_id'=>'PB-SQL','name'=>'Phòng ban SQL đổi']);$commit($batch['id']);$check($department->fresh()->manager_id===$actor->id,'department update preserves blank manager');
$userInput=['external_id'=>'NV-SQL','name'=>'Nhân viên SQL','email'=>'org-sql@example.test','department_ref'=>'ext:PB-SQL','position_ref'=>'ext:CV-SQL','manager_ref'=>$actor->email];
$count=App\Models\User::count();$batch=$productPreview('users',$userInput);$check($batch['status']==='ready' && App\Models\User::count()===$count,'user preview without writes');$commit($batch['id']);
$imported=App\Models\User::where('email',$userInput['email'])->firstOrFail();$check(!$imported->is_active && $imported->roles()->count()===0 && $imported->department_id===$department->id && $imported->position_id===$position->id,'user inactive roleless with exact references');
$commit($batch['id']);$check(App\Models\User::count()===$count+1,'user commit replay safe');
$userInput['name']='Không cập nhật';$batch=$productPreview('users',$userInput);$check($batch['status']==='invalid','user Excel update blocked');
