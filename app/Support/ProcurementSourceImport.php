<?php
namespace App\Support;

use App\Models\{Supplier,Warehouse,WarehouseLocation,User};
use App\Http\Controllers\Api\V1\{SupplierController,WarehouseController};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema,Validator};
use Illuminate\Validation\ValidationException;

final class ProcurementSourceImport
{
    private const TECH=['schema_version'=>'Phiên bản mẫu','external_id'=>'Mã ngoài','source_revision'=>'Phiên bản nguồn'];
    public const FIELDS=[
        'suppliers'=>['name'=>'Tên nhà cung cấp','tax_code'=>'Mã số thuế','phone'=>'Số điện thoại','email'=>'Email','address'=>'Địa chỉ','contact_name'=>'Người liên hệ','payment_terms'=>'Điều khoản thanh toán','status'=>'Trạng thái'],
        'warehouses'=>['name'=>'Tên kho','address'=>'Địa chỉ','manager_ref'=>'Người quản lý','status'=>'Trạng thái'],
        'locations'=>['warehouse_ref'=>'Kho','code'=>'Mã vị trí','name'=>'Tên vị trí','status'=>'Trạng thái'],
    ];
    public static function labels(string $kind):array{return self::TECH+self::FIELDS[$kind];}
    private function fail(string $message):never{throw ValidationException::withMessages(['file'=>$message]);}
    private function query(string $kind,int $tenant){return match($kind){'suppliers'=>Supplier::where('tenant_id',$tenant),'warehouses'=>Warehouse::where('tenant_id',$tenant),'locations'=>WarehouseLocation::whereIn('warehouse_id',Warehouse::where('tenant_id',$tenant)->select('id'))};}
    private function snapshot($item,string $kind):array{
        $fields=array_keys(self::FIELDS[$kind]);$fields=array_diff($fields,['manager_ref','warehouse_ref']);
        $values=$item->only($fields);
        if($kind==='warehouses')$values['manager_id']=$item->manager_id;
        if($kind==='locations')$values['warehouse_id']=$item->warehouse_id;
        return $values+['_updated_at'=>$item->getRawOriginal('updated_at')];
    }
    private function warehouse($actor,int $source,string $reference):Warehouse{
        if(!preg_match('/^(crm|ext):(.+)$/D',$reference,$m))$this->fail('Kho cần chọn từ danh sách hoặc dùng crm:Mã-kho / ext:Mã-ngoài của kho đã nhập.');
        $query=Warehouse::where('tenant_id',$actor->tenant_id)->where('status','active');
        if($m[1]==='crm')$query->where('code',$m[2]);
        else $query->whereKey(DB::table('import_record_links')->where('tenant_id',$actor->tenant_id)->where('source_id',$source)->where('catalog','warehouses')->where('external_id',strtolower($m[2]))->value('record_id'));
        $warehouse=$query->lockForUpdate()->first();if(!$warehouse)$this->fail('Không tìm thấy kho đang hoạt động trong công ty. Nhập kho trước vị trí kho.');return $warehouse;
    }
    private function normalized(string $field,string $value):string{
        $value=trim($value);return match($field){'phone'=>preg_replace('/^84(?=\d{9,10}$)/','0',preg_replace('/\D/','',$value)),'tax_code'=>preg_replace('/[.\s-]/','',$value),default=>mb_strtolower($value)};
    }
    public function run(Request $request,string $path,int $source,bool $confirm,string $kind):array{
        if(DB::connection()->transactionLevel()<1)throw new \LogicException('Nhập danh mục cần giao dịch lô.');
        abort_unless(Schema::hasTable('import_record_links'),503,'Chưa nâng cấp liên kết nguồn nhập.');
        $actor=$request->user();$labels=self::labels($kind);$rows=(new CustomerImportWorkbook($labels,['external_id','tax_code','phone','code']))->read($path);$plans=[];$errors=[];$seen=[];$seenBusiness=[];$existing=[];
        $identityFields=$kind==='suppliers'?['name','tax_code','phone','email']:($kind==='locations'?['name','code']:['name']);
        $columns=array_merge(['id'],$identityFields,$kind==='locations'?['warehouse_id']:[]);
        foreach($this->query($kind,$actor->tenant_id)->select($columns)->cursor() as $other)foreach($identityFields as $field)if(filled($other->$field)){
            $key=$field.':'.($kind==='locations'?$other->warehouse_id.':':'').$this->normalized($field,$other->$field);$existing[$key][]=$other->id;
        }
        foreach($rows as $row)try{
            $raw=$row['data'];$automatic=!array_key_exists('source_revision',$raw);$raw['schema_version']??='1';$raw['source_revision']??='1';
            Validator::make($raw,['schema_version'=>'required|in:1','external_id'=>'nullable|string|max:100|regex:/^[A-Za-z0-9_.-]+$/','source_revision'=>'required|integer|min:1|max:2147483647'],ImportValidation::MESSAGES,$labels)->validate();
            $external=strtolower(trim($raw['external_id'] ?? ''));if($external!=='' && isset($seen[$external]))$this->fail('Mã ngoài bị trùng trong file.');if($external!=='')$seen[$external]=true;
            $link=$external!==''?DB::table('import_record_links')->where('tenant_id',$actor->tenant_id)->where('source_id',$source)->where('catalog',$kind)->where('external_id',$external)->first():null;
            $item=$link?$this->query($kind,$actor->tenant_id)->whereKey($link->record_id)->lockForUpdate()->first():null;
            if($link && !$item)$this->fail('Hồ sơ đã liên kết không còn tồn tại trong công ty. Không tự ghép hồ sơ khác.');
            $values=[];foreach(self::FIELDS[$kind] as $key=>$label)if(!in_array($key,['manager_ref','warehouse_ref'],true))$values[$key]=$raw[$key] ?? null;
            if(filled($values['status']))$values['status']=match(mb_strtolower($values['status'])){'hoạt động'=>'active','ngừng hoạt động','không hoạt động'=>'inactive',default=>$values['status']};
            if(!$item)$values['status']??='active';
            if($kind==='warehouses'){
                $values['manager_id']=null;
                if(filled($raw['manager_ref'] ?? null)){
                    $email=preg_replace('/^crm:/i','',trim($raw['manager_ref']));$manager=User::where('tenant_id',$actor->tenant_id)->where('is_active',true)->where('email',$email)->first();
                    if(!$manager)$this->fail('Người quản lý không hợp lệ. Chọn email người đang hoạt động trong công ty.');$values['manager_id']=$manager->id;
                }
            }
            if($kind==='locations'){
                Validator::make($raw,['warehouse_ref'=>'required|string|max:150'],ImportValidation::MESSAGES,$labels)->validate();
                $values['warehouse_id']=$this->warehouse($actor,$source,$raw['warehouse_ref'])->id;
                if($item && $values['warehouse_id']!==$item->warehouse_id)$this->fail('Không được chuyển vị trí đã nhập sang kho khác.');
            }
            $current=$item?$this->snapshot($item,$kind):[];$revision=$automatic && $link?(int)$link->source_revision+(hash_equals($link->source_hash,ImportRevision::hash($values))?0:1):(int)$raw['source_revision'];
            $plan=ImportRevision::plan($link?(array)$link:null,$revision,$values,$current,$kind!=='locations');$final=$plan['values'];
            $rules=['name'=>'required|string|max:255','status'=>'required|in:active,inactive'];
            if($kind==='suppliers')$rules+=['tax_code'=>'nullable|string|max:30|regex:/^[0-9.\s-]+$/','phone'=>'nullable|string|max:50','email'=>'nullable|email|max:255','address'=>'nullable|string|max:255','contact_name'=>'nullable|string|max:255','payment_terms'=>'nullable|string|max:255'];
            if($kind==='warehouses')$rules+=['address'=>'nullable|string|max:255'];
            if($kind==='locations')$rules+=['code'=>'nullable|string|max:50'];
            Validator::make($final,$rules,ImportValidation::MESSAGES,$labels)->validate();
            foreach($identityFields as $field)if(filled($final[$field] ?? null)){
                $key=$this->normalized($field,$final[$field]);$bucket=$field.':'.($kind==='locations'?$final['warehouse_id'].':':'').$key;
                if(isset($seenBusiness[$bucket]))$this->fail(($labels[$field] ?? 'Thông tin').' trùng trong file.');
                if(array_diff($existing[$bucket] ?? [],$item?[$item->id]:[]))$this->fail(($labels[$field] ?? 'Thông tin').' trùng hồ sơ đã có. Không tự ghép hoặc ghi đè.');
                $seenBusiness[$bucket]=true;
            }
            $plans[]=compact('external','revision','item','plan')+['row'=>$row['row']];
        }catch(ValidationException $e){$errors[]=['row'=>$row['row'],'message'=>implode(' ',array_merge(...array_values($e->errors())))];}
        $preview=array_map(fn($p)=>['row'=>$p['row'],'external_id'=>$p['external'],'action'=>$p['plan']['action'],'changes'=>$p['plan']['changes'],'crm_hash'=>$p['item']?ImportRevision::hash($this->snapshot($p['item'],$kind)):null]+array_diff_key($p['plan']['values'],['_updated_at'=>1]),$plans);
        foreach($preview as &$line){
            $line['reference_label']='';
            if($kind==='locations'){$warehouse=Warehouse::where('tenant_id',$actor->tenant_id)->find($line['warehouse_id']);$line['reference_label']=$warehouse?$warehouse->code.' · '.$warehouse->name:'';}
            if($kind==='warehouses' && !empty($line['manager_id'])){$manager=User::where('tenant_id',$actor->tenant_id)->find($line['manager_id']);$line['reference_label']=$manager?$manager->name.' · '.$manager->email:'';}
        }unset($line);
        $imported=0;if(!$errors && $confirm)foreach($plans as $p){
            if($p['plan']['action']==='skip')continue;
            $data=array_diff_key($p['plan']['values'],['_updated_at'=>1]);$child=Request::create('/internal/catalog-import','POST',$data);$child->setUserResolver(fn()=>$actor);
            if($kind==='locations'){
                $warehouse=Warehouse::where('tenant_id',$actor->tenant_id)->where('status','active')->lockForUpdate()->findOrFail($data['warehouse_id']);
                $data['code']??=app(CodeGenerator::class)->next('warehouse_locations','code','LOC-');unset($data['warehouse_id']);$item=$warehouse->locations()->create($data);
                app(AuditLogger::class)->record('warehouse_location',$item->id,'create_location_import',$actor,null,$item->toArray());
            }else{
                $controller=app($kind==='suppliers'?SupplierController::class:WarehouseController::class);$response=$p['item']?$controller->update($child,$p['item']):$controller->store($child);
                if(!in_array($response->getStatusCode(),[200,201],true))$this->fail('Không lưu được hồ sơ. Chưa ghi toàn bộ lô.');
                $item=$this->query($kind,$actor->tenant_id)->findOrFail($response->getData(true)['data']['id']);
            }
            if($p['external']!==''){
                $identity=['tenant_id'=>$actor->tenant_id,'source_id'=>$source,'catalog'=>$kind,'external_id'=>$p['external']];$created=$p['item']?DB::table('import_record_links')->where($identity)->value('created_at'):now();
                DB::table('import_record_links')->updateOrInsert($identity,['record_id'=>$item->id,'source_revision'=>$p['revision'],'source_hash'=>$p['plan']['source_hash'],'crm_hash'=>ImportRevision::hash($this->snapshot($item,$kind)),'created_at'=>$created,'updated_at'=>now()]);
            }$imported++;
        }
        return ['total'=>count($rows),'can_import'=>!$errors,'imported'=>$imported,'skipped'=>count(array_filter($plans,fn($p)=>$p['plan']['action']==='skip')),'errors'=>$errors,'preview'=>$preview];
    }
}
