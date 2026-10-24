<?php
namespace App\Support;

use App\Models\{ProductCategory,ProductBrand,Sku,Product};
use App\Http\Controllers\Api\V1\{ProductCatalogController,SkuController};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema,Validator};
use Illuminate\Validation\ValidationException;

final class ProductSourceImport
{
    public const BASIC=['schema_version'=>'Phiên bản mẫu','external_id'=>'Mã ngoài','source_revision'=>'Phiên bản nguồn','name'=>'Tên','status'=>'Trạng thái'];
    public const SKU=['schema_version'=>'Phiên bản mẫu','external_id'=>'Mã ngoài','source_revision'=>'Phiên bản nguồn','name'=>'Tên mã hàng','product_code'=>'Mã sản phẩm','sku_code'=>'Mã hàng','product_name'=>'Tên sản phẩm','category_ref'=>'Danh mục sản phẩm','brand_ref'=>'Hãng sản phẩm','serial_number'=>'Số serial','barcode'=>'Mã vạch','unit'=>'Đơn vị tính','min_stock'=>'Tồn tối thiểu','max_stock'=>'Tồn tối đa','sale_price'=>'Giá bán','cost_price'=>'Giá vốn','status'=>'Trạng thái','description'=>'Mô tả'];
    private const NUMBERS=['min_stock','max_stock','sale_price','cost_price'];
    private function fail(string $message):never {throw ValidationException::withMessages(['file'=>$message]);}
    private function model(string $kind):string {return match($kind){'categories'=>ProductCategory::class,'brands'=>ProductBrand::class,'skus'=>Sku::class};}
    private function snapshot($item,string $kind):array {
        if($kind!=='skus')return $item->only(['name','status'])+['_updated_at'=>$item->getRawOriginal('updated_at')];
        $values=$item->only(['name','sku_code','serial_number','barcode','unit','status']);
        foreach(self::NUMBERS as $field)$values[$field]=(float)$item->$field;
        $p=$item->product;$values+=['product_code'=>$p?->code,'product_name'=>$p?->name,'category_id'=>$p?->category_id,'brand_id'=>$p?->brand_id,'description'=>$p?->description];
        return $values+['_updated_at'=>$item->getRawOriginal('updated_at'),'_product_updated_at'=>$p?->getRawOriginal('updated_at')];
    }
    private function reference($actor,int $source,string $kind,string $value):int {
        if(!preg_match('/^(crm|ext):(.+)$/D',trim($value),$m))$this->fail('Danh mục và hãng cần chọn từ danh sách hoặc dùng ext:mã-ngoài đã nhập trước.');
        $model=$this->model($kind);$id=null;
        if($m[1]==='crm'){
            $matches=$model::where('tenant_id',$actor->tenant_id)->where('status','active')->where('name',$m[2])->get(['id']);
            if($matches->count()>1)$this->fail('Tên danh mục/hãng không duy nhất. Dùng Mã ngoài đã liên kết để đối chiếu.');
            $id=$matches->first()?->id;
        }else $id=DB::table('import_record_links')->where('tenant_id',$actor->tenant_id)->where('source_id',$source)->where('catalog',$kind)->where('external_id',strtolower($m[2]))->value('record_id');
        if(!$id || !$model::where('tenant_id',$actor->tenant_id)->where('status','active')->whereKey($id)->exists())$this->fail('Không tìm thấy danh mục hoặc hãng đang hoạt động trong công ty. Nhập danh mục và hãng trước sản phẩm.');
        return (int)$id;
    }
    public function run(Request $request,string $path,int $source,bool $confirm,string $kind):array {
        if(DB::connection()->transactionLevel()<1)throw new \LogicException('Nhập sản phẩm cần giao dịch lô.');
        abort_unless(Schema::hasTable('import_record_links'),503,'Chưa nâng cấp liên kết nguồn nhập.');
        $actor=$request->user();$isSku=$kind==='skus';abort_if($isSku && !$actor->hasPermission('master.cost.view'),403,'Nhập sản phẩm có giá vốn cần quyền xem giá vốn.');
        $labels=$isSku?self::SKU:self::BASIC;$rows=(new CustomerImportWorkbook($labels,['external_id','product_code','sku_code','serial_number','barcode']))->read($path);
        $model=$this->model($kind);$plans=[];$errors=[];$seen=[];$keys=[];
        foreach($rows as $row)try{
            $raw=$row['data'];$automatic=!array_key_exists('source_revision',$raw);$raw['schema_version']??='1';$raw['source_revision']??='1';
            Validator::make($raw,['schema_version'=>'required|in:1','external_id'=>'nullable|string|max:100|regex:/^[A-Za-z0-9_.-]+$/','source_revision'=>'required|integer|min:1|max:2147483647'],ImportValidation::MESSAGES,$labels)->validate();
            $external=strtolower(trim($raw['external_id'] ?? ''));if($external!=='' && isset($seen[$external]))$this->fail('Mã ngoài bị trùng trong file.');if($external!=='')$seen[$external]=true;
            $link=$external!==''?DB::table('import_record_links')->where('tenant_id',$actor->tenant_id)->where('source_id',$source)->where('catalog',$kind)->where('external_id',$external)->first():null;
            $item=$link?$model::where('tenant_id',$actor->tenant_id)->whereKey($link->record_id)->lockForUpdate()->first():null;
            if($link && !$item)$this->fail('Hồ sơ đã liên kết không còn tồn tại. Không tự ghép hồ sơ khác.');
            $values=[];foreach(array_diff(array_keys($labels),['schema_version','external_id','source_revision','category_ref','brand_ref']) as $field)$values[$field]=$raw[$field] ?? null;
            if(filled($values['status']))$values['status']=match(mb_strtolower($values['status'])){'hoạt động'=>'active','ngừng hoạt động','không hoạt động'=>'inactive',default=>$values['status']};
            if(!$item)$values['status']??='active';
            if($isSku){
                foreach(self::NUMBERS as $field){if(filled($values[$field])){if(!is_numeric($values[$field]) || !is_finite((float)$values[$field]))$this->fail($labels[$field].' phải là số không âm, không dùng dấu phân cách hàng nghìn.');$values[$field]=(float)$values[$field];}elseif(!$item)$values[$field]=0.0;}
                foreach(['category_ref'=>'category_id','brand_ref'=>'brand_id'] as $ref=>$field)$values[$field]=filled($raw[$ref] ?? null)?$this->reference($actor,$source,$ref==='category_ref'?'categories':'brands',$raw[$ref]):null;
                if(!$item){$values['unit']??='pcs';$values['product_name']??=$values['name'];}
            }
            $current=$item?$this->snapshot($item,$kind):[];
            $revision=$automatic && $link?(int)$link->source_revision+(hash_equals($link->source_hash,ImportRevision::hash($values))?0:1):(int)$raw['source_revision'];
            $plan=ImportRevision::plan($link?(array)$link:null,$revision,$values,$current,$isSku);
            $final=$plan['values'];$rules=['name'=>'required|string|max:'.($isSku?'255':'100'),'status'=>'required|in:active,inactive'];
            if($isSku)$rules+=['product_code'=>'nullable|string|max:100','sku_code'=>'nullable|string|max:100','product_name'=>'nullable|string|max:255','serial_number'=>'nullable|string|max:120','barcode'=>'nullable|string|max:100','unit'=>'nullable|string|max:30','description'=>'nullable|string|max:2048']+array_fill_keys(self::NUMBERS,'nullable|numeric|min:0');
            Validator::make($final,$rules,ImportValidation::MESSAGES,$labels)->validate();
            if($isSku && $final['max_stock']<$final['min_stock'])$this->fail('Tồn tối đa phải lớn hơn hoặc bằng tồn tối thiểu.');
            $checks=$isSku?['sku_code'=>'skus','product_code'=>'products']:['name'=>$item?->getTable() ?? (new $model)->getTable()];
            foreach($checks as $field=>$table)if(filled($final[$field] ?? null)){
                $key=$field.':'.mb_strtolower(trim($final[$field]));$exclude=$field==='product_code'?$item?->product_id:$item?->id;
                $query=DB::table($table)->where('tenant_id',$actor->tenant_id)->whereRaw('LOWER(TRIM('.($field==='product_code'?'code':$field).')) = ?', [mb_strtolower(trim($final[$field]))]);
                if($exclude)$query->where('id','!=',$exclude);
                if(isset($keys[$key]) || $query->exists())$this->fail(($labels[$field] ?? 'Tên').' bị trùng trong file hoặc hệ thống. Không tự ghép hồ sơ.');$keys[$key]=true;
            }
            if($isSku && !$item && empty($final['sku_code'])){
                $key='name-unit:'.mb_strtolower(trim($final['name'])).':'.($final['unit'] ?? 'pcs');
                if(isset($keys[$key]) || Sku::where('tenant_id',$actor->tenant_id)->whereRaw('LOWER(TRIM(name)) = ?',[mb_strtolower(trim($final['name']))])->where('unit',$final['unit'] ?? 'pcs')->exists())$this->fail('Tên mã hàng và đơn vị tính đã có. Điền mã ngoài của hồ sơ đã liên kết để cập nhật.');$keys[$key]=true;
            }
            $plans[]=compact('external','revision','item','plan')+['row'=>$row['row']];
        }catch(ValidationException $e){$errors[]=['row'=>$row['row'],'message'=>implode(' ',array_merge(...array_values($e->errors())))];}
        $preview=array_map(fn($p)=>['row'=>$p['row'],'external_id'=>$p['external'],'action'=>$p['plan']['action'],'changes'=>$p['plan']['changes'],'crm_hash'=>$p['item']?ImportRevision::hash($this->snapshot($p['item'],$kind)):null]+array_diff_key($p['plan']['values'],['_updated_at'=>1,'_product_updated_at'=>1]),$plans);
        $imported=0;if(!$errors && $confirm)foreach($plans as $p){
            if($p['plan']['action']==='skip')continue;
            $data=array_diff_key($p['plan']['values'],['_updated_at'=>1,'_product_updated_at'=>1]);$child=Request::create('/internal/product-import','POST',$data);$child->setUserResolver(fn()=>$actor);
            if($isSku){$controller=app(SkuController::class);$response=$p['item']?$controller->update($child,$p['item']):$controller->store($child);}
            else{$controller=app(ProductCatalogController::class);$response=$kind==='categories'?$controller->storeCategory($child):$controller->storeBrand($child);if($response->getStatusCode()!==201)$this->fail('Tên danh mục/hãng đã tồn tại. Chưa ghi lô nhập.');}
            $item=$model::where('tenant_id',$actor->tenant_id)->findOrFail($response->getData(true)['data']['id']);
            if($p['external']!=='')DB::table('import_record_links')->updateOrInsert(['tenant_id'=>$actor->tenant_id,'source_id'=>$source,'catalog'=>$kind,'external_id'=>$p['external']],['record_id'=>$item->id,'source_revision'=>$p['revision'],'source_hash'=>$p['plan']['source_hash'],'crm_hash'=>ImportRevision::hash($this->snapshot($item,$kind)),'created_at'=>$p['item']?DB::table('import_record_links')->where('tenant_id',$actor->tenant_id)->where('source_id',$source)->where('catalog',$kind)->where('external_id',$p['external'])->value('created_at'):now(),'updated_at'=>now()]);
            app(AuditLogger::class)->record('import_'.$kind,$item->id,'commit_import_row',$actor,null,['source_id'=>$source,'row'=>$p['row'],'action'=>$p['plan']['action']]);$imported++;
        }
        return ['total'=>count($rows),'can_import'=>!$errors,'imported'=>$imported,'skipped'=>count(array_filter($plans,fn($p)=>$p['plan']['action']==='skip')),'errors'=>$errors,'preview'=>$preview];
    }
}
