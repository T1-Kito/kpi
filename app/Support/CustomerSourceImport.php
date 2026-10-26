<?php
namespace App\Support;

use App\Models\Customer;
use App\Http\Controllers\Api\V1\CustomerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema,Validator};
use Illuminate\Validation\ValidationException;

/** Adapter for explicitly versioned customer workbooks; legacy files remain separate. */
final class CustomerSourceImport
{
    public const FIELDS=['name'=>'Tên khách hàng','customer_type'=>'Loại khách hàng','tax_code'=>'Mã số thuế','identity_number'=>'Số CCCD','phone'=>'Số điện thoại','email'=>'Email','address'=>'Địa chỉ','billing_address'=>'Địa chỉ hóa đơn','legal_representative'=>'Người đại diện pháp luật','representative_position'=>'Chức vụ đại diện','credit_limit'=>'Hạn mức công nợ','status'=>'Trạng thái','sales_owner_ref'=>'Người phụ trách'];
    public const LABELS=['schema_version'=>'Phiên bản mẫu','external_id'=>'Mã ngoài','source_revision'=>'Phiên bản nguồn']+self::FIELDS+CustomerGroupReference::LABELS;
    private function snapshot(Customer $customer): array {
        $values=$customer->only(array_diff(array_keys(self::FIELDS),['sales_owner_ref']));
        $values['credit_limit']=$customer->credit_limit===null?null:(float)$customer->credit_limit;
        $values['sales_owner_ref']=$customer->sales_owner_id?'crm:'.\App\Models\User::whereKey($customer->sales_owner_id)->value('email'):null;
        return $values+CustomerGroupReference::names($customer)+['_updated_at'=>$customer->getRawOriginal('updated_at'),'_merged'=>$customer->merged_into_id];
    }
    public function run(Request $request,string $path,int $sourceId,bool $confirm): array
    {
        if(DB::connection()->transactionLevel()<1)throw new \LogicException('Nhập theo nguồn phải nằm trong giao dịch lô nhập.');
        abort_unless(Schema::hasTable('import_record_links'),503,'Cần nâng cấp liên kết nguồn trước khi nhập theo phiên bản.');
        $rows=(new CustomerImportWorkbook(self::LABELS,['external_id','phone','tax_code','identity_number']))->read($path);
        $actor=$request->user();$errors=[];$plans=[];$seen=[];$keys=[];
        foreach($rows as $row){
            try{
                $raw=$row['data'];
                $automatic=!array_key_exists('source_revision',$raw);$raw['schema_version']??='1';$raw['source_revision']??='1';
Validator::make($raw,['schema_version'=>'required|in:1','external_id'=>'nullable|string|max:100|regex:/^[A-Za-z0-9_.-]+$/','source_revision'=>'required|integer|min:1|max:2147483647'],ImportValidation::MESSAGES,self::LABELS)->validate();
                $external=strtolower(trim($raw['external_id'] ?? ''));
                if($external!=='' && isset($seen[$external]))throw ValidationException::withMessages(['file'=>'Mã ngoài trùng trong file.']);
                if($external!=='')$seen[$external]=true;
                $fields=self::FIELDS+CustomerGroupReference::LABELS;
                $incoming=array_replace(array_fill_keys(array_keys($fields),null),array_intersect_key($raw,$fields));
                if(filled($incoming['credit_limit'])){
                    if(!is_numeric($incoming['credit_limit']) || !is_finite((float)$incoming['credit_limit']) || (float)$incoming['credit_limit']<0)throw ValidationException::withMessages(['file'=>'Hạn mức công nợ phải là số hữu hạn không âm.']);
                    $incoming['credit_limit']=(float)$incoming['credit_limit'];
                }
                if(filled($incoming['customer_type']))$incoming['customer_type']=CustomerImportWorkbook::type($incoming['customer_type']);
                if(filled($incoming['status']))$incoming['status']=match(mb_strtolower(trim($incoming['status']))){'hoạt động'=>'active','ngừng hoạt động','không hoạt động'=>'inactive',default=>$incoming['status']};
                if(filled($incoming['sales_owner_ref']) && !str_starts_with($incoming['sales_owner_ref'],'crm:'))$incoming['sales_owner_ref']='crm:'.trim($incoming['sales_owner_ref']);
                $link=DB::table('import_record_links')->where('tenant_id',$actor->tenant_id)->where('source_id',$sourceId)->where('catalog','customers')->where('external_id',$external)->first();
                $customer=$link?Customer::where('tenant_id',$actor->tenant_id)->whereKey($link->record_id)->lockForUpdate()->first():null;
                if($link && (!$customer || $customer->merged_into_id))throw ValidationException::withMessages(['file'=>'Hồ sơ liên kết không còn hợp lệ. Không tự nhận hồ sơ khác.']);
                if(!$link && !$incoming['customer_type'])$incoming['customer_type']='organization';
                if(!$link){$incoming['status']??='active';$incoming['credit_limit']??=0.0;}
                if($automatic && $link)$raw['source_revision']=(int)$link->source_revision+(hash_equals($link->source_hash,ImportRevision::hash($incoming))?0:1);
                $current=$customer?$this->snapshot($customer):[];
                // Old mappings did not include the optional group fields. Accept only an exact legacy snapshot match.
                if($link && !$customer->customer_group_id){
                    $legacy=array_diff_key($current,CustomerGroupReference::LABELS);
                    if(hash_equals($link->crm_hash,ImportRevision::hash($legacy)))$link->crm_hash=ImportRevision::hash($current);
                }
                $plan=ImportRevision::plan($link?(array)$link:null,(int)$raw['source_revision'],$incoming,$current,true);
                $values=$plan['values'];unset($values['_updated_at'],$values['_merged']);
                // A supplied path replaces the whole path, never combines a new parent with old children.
                $supplied=array_intersect_key($raw,CustomerGroupReference::LABELS);
                if(array_filter($supplied,fn($v)=>$v!==null && trim((string)$v)!=='')){
                    foreach(CustomerGroupReference::LABELS as $key=>$label){
                        $value=trim((string)($raw[$key] ?? ''));$values[$key]=$value===''?null:$value;
                        if($plan['action']==='update' && ($current[$key] ?? null)!==$values[$key])$plan['changes'][$key]=['before'=>$current[$key] ?? null,'after'=>$values[$key]];
                    }
                }
                $groupId=CustomerGroupReference::resolve($values,(int)$actor->tenant_id,$customer);
                $groupPath=implode(' / ',array_filter(array_intersect_key($values,CustomerGroupReference::LABELS),fn($v)=>$v!==null && $v!==''));
                foreach(array_keys(CustomerGroupReference::LABELS) as $key)unset($values[$key]);
                if($groupId!==null)$values['customer_group_id']=$groupId;
                $owner=$customer?->sales_owner_id ?? $actor->id;
                if(filled($values['sales_owner_ref'])){
                    if(!str_starts_with($values['sales_owner_ref'],'crm:'))throw ValidationException::withMessages(['file'=>'Người phụ trách cần dùng crm:email.']);
                    $target=\App\Models\User::where('tenant_id',$actor->tenant_id)->where('is_active',true)->where('email',substr($values['sales_owner_ref'],4))->first();
                    if(!$target)throw ValidationException::withMessages(['file'=>'Người phụ trách không hợp lệ hoặc không thuộc doanh nghiệp.']);$owner=$target->id;
                }
                $values['sales_owner_id']=$owner;unset($values['sales_owner_ref']);
                Validator::make($values,['name'=>'required|string|max:255','customer_type'=>'required|in:person,organization','phone'=>'nullable|string|max:50','email'=>'nullable|email|max:255','tax_code'=>'nullable|regex:/^[0-9.\s-]+$/|max:30','identity_number'=>'nullable|regex:/^[0-9]{12}$/','contact_name'=>'nullable|string|max:255','address'=>'nullable|string|max:255'],ImportValidation::MESSAGES,self::FIELDS)->validate();
                Validator::make($values,['billing_address'=>'nullable|string|max:255','legal_representative'=>'nullable|string|max:255','representative_position'=>'nullable|string|max:255','status'=>'nullable|in:active,inactive','credit_limit'=>'nullable|numeric|min:0'],ImportValidation::MESSAGES,self::FIELDS)->validate();
                if($customer && $values['customer_type']!==$customer->customer_type)throw ValidationException::withMessages(['file'=>'Không đổi loại khách hàng bằng file cập nhật.']);
                $identity=$this->keys($values);
                if(!$identity)throw ValidationException::withMessages(['file'=>'Cần điện thoại, email, mã số thuế hoặc CCCD để kiểm tra trùng.']);
                foreach($identity as $key){if(isset($keys[$key]))throw ValidationException::withMessages(['file'=>'Thông tin nhận diện trùng dòng khác trong file.']);$keys[$key]=true;}
                foreach(Customer::withoutGlobalScope('catalog_visibility')->where('tenant_id',$actor->tenant_id)->when($customer,fn($q)=>$q->whereKeyNot($customer->id))->select(['id','phone','email','tax_code','identity_number','customer_type'])->cursor() as $existing){
                    if(array_intersect($identity,$this->keys($existing->toArray())))throw ValidationException::withMessages(['file'=>'Thông tin nhận diện đã thuộc hồ sơ khác. Không tự gộp hoặc ghi đè.']);
                }
                $plans[]=['row'=>$row['row'],'external_id'=>$external,'revision'=>(int)$raw['source_revision'],'customer'=>$customer,'plan'=>$plan,'values'=>$values,'group_path'=>$groupPath];
            }catch(ValidationException $e){$errors[]=['row'=>$row['row'],'message'=>implode(' ',array_merge(...array_values($e->errors())))];}
        }
        $preview=array_map(fn($p)=>['row'=>$p['row'],'external_id'=>$p['external_id'],'source_revision'=>$p['revision'],'action'=>$p['plan']['action'],'changes'=>$p['plan']['changes'],'group_path'=>$p['group_path'],'crm_hash'=>$p['customer']?ImportRevision::hash($this->snapshot($p['customer'])):null]+$p['values'],$plans);
        $imported=0;
        if(!$errors && $confirm){
            foreach($plans as $p){
                if($p['plan']['action']==='skip')continue;
                $child=Request::create('/internal/customer','POST',$p['values']+($p['customer']?[]:['sales_owner_id'=>$actor->id]));$child->setUserResolver(fn()=>$actor);
                $controller=app(CustomerController::class);
                $response=$p['customer']?$controller->update($child,$p['customer']):$controller->store($child);
                if($response->getStatusCode()>=300)throw ValidationException::withMessages(['file'=>'Dòng '.$p['row'].' không thể lưu. Chưa ghi lô nhập.']);
                $id=$response->getData(true)['data']['id'];$saved=Customer::where('tenant_id',$actor->tenant_id)->findOrFail($id);
                if($p['external_id']===''){$imported++;continue;}
                $identity=['tenant_id'=>$actor->tenant_id,'source_id'=>$sourceId,'catalog'=>'customers','external_id'=>$p['external_id']];
                $mapping=['record_id'=>$id,'source_revision'=>$p['revision'],'source_hash'=>$p['plan']['source_hash'],'crm_hash'=>ImportRevision::hash($this->snapshot($saved)),'updated_at'=>now()];
                if($p['customer'])DB::table('import_record_links')->where($identity)->update($mapping);
                else DB::table('import_record_links')->insert($identity+$mapping+['created_at'=>now()]);
                $imported++;
            }
        }
        return ['total'=>count($rows),'imported'=>$imported,'skipped'=>count(array_filter($plans,fn($p)=>$p['plan']['action']==='skip')),'can_import'=>!$errors,'errors'=>$errors,'preview'=>$preview];
    }
    private function keys(array $d): array {
        $phone=preg_replace('/\D/','',$d['phone'] ?? '');if(str_starts_with($phone,'84') && strlen($phone)>=11)$phone='0'.substr($phone,2);
        $values=['phone'=>$phone,'email'=>strtolower(trim($d['email'] ?? '')),'tax'=>($d['customer_type'] ?? '')==='organization'?BusinessIdentity::tax($d['tax_code'] ?? null):null,'identity'=>($d['customer_type'] ?? '')==='person'?($d['identity_number'] ?? null):null];
        $keys=[];foreach($values as $key=>$value)if($value!==null && $value!=='')$keys[]=$key.':'.$value;return $keys;
    }
}
