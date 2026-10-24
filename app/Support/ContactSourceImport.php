<?php
namespace App\Support;

use App\Models\CustomerContact;
use App\Http\Controllers\Api\V1\CustomerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema,Validator};
use Illuminate\Validation\ValidationException;

final class ContactSourceImport
{
    public const LABELS=['schema_version'=>'Phiên bản mẫu','external_id'=>'Mã ngoài','source_revision'=>'Phiên bản nguồn','customer_ref'=>'Mã tham chiếu khách hàng','name'=>'Tên người liên hệ','phone'=>'Số điện thoại','email'=>'Email','position'=>'Chức vụ','is_primary'=>'Liên hệ chính'];
    private function snapshot(CustomerContact $contact):array {
        return $contact->only(['customer_id','name','phone','email','position'])+['is_primary'=>$contact->is_primary,'_updated_at'=>$contact->getRawOriginal('updated_at')];
    }
    private function fail(string $message):never {throw ValidationException::withMessages(['file'=>$message]);}
    private function keys(array $d):array {
        $phone=preg_replace('/\D/','',$d['phone'] ?? '');if(str_starts_with($phone,'84') && strlen($phone)>=11)$phone='0'.substr($phone,2);
        return array_filter([$phone?'p:'.$phone:null,filled($d['email'] ?? null)?'e:'.mb_strtolower(trim($d['email'])):null]);
    }
    public function run(Request $request,string $path,int $sourceId,bool $confirm):array {
        if(DB::connection()->transactionLevel()<1)throw new \LogicException('Nhập người liên hệ cần giao dịch lô.');
        abort_unless(Schema::hasTable('import_record_links'),503,'Chưa nâng cấp liên kết nguồn nhập.');
        $rows=(new CustomerImportWorkbook(self::LABELS,['external_id','customer_ref','phone']))->read($path);
        $actor=$request->user();$plans=[];$errors=[];$seen=[];$seenKeys=[];$primaryCustomers=[];
        foreach($rows as $row){try{
            $raw=$row['data'];$automatic=!array_key_exists('source_revision',$raw);$raw['schema_version']??='1';$raw['source_revision']??='1';
Validator::make($raw,['schema_version'=>'required|in:1','external_id'=>'nullable|string|max:100|regex:/^[A-Za-z0-9_.-]+$/','source_revision'=>'required|integer|min:1|max:2147483647','customer_ref'=>'required|string|max:150'],ImportValidation::MESSAGES,self::LABELS)->validate();
            $external=strtolower(trim($raw['external_id'] ?? ''));if($external!=='' && isset($seen[$external]))$this->fail('Mã ngoài trùng trong file.');if($external!=='')$seen[$external]=true;
            $customer=app(ImportCustomerReference::class)->resolve($actor,$sourceId,$raw['customer_ref']);
            $link=DB::table('import_record_links')->where('tenant_id',$actor->tenant_id)->where('source_id',$sourceId)->where('catalog','contacts')->where('external_id',$external)->first();
            $contact=$link?CustomerContact::whereKey($link->record_id)->whereHas('customer',fn($q)=>$q->where('tenant_id',$actor->tenant_id))->lockForUpdate()->first():null;
            if($link && (!$contact || $contact->customer_id!==$customer->id))$this->fail('Liên kết không còn hợp lệ hoặc file đổi khách hàng của người liên hệ. Không tự chuyển khách.');
            $incoming=['customer_id'=>$customer->id];foreach(['name','phone','email','position'] as $field)$incoming[$field]=$raw[$field] ?? null;
            $primary=$raw['is_primary'] ?? null;
            if($primary!==null && trim((string)$primary)!==''){
                $primary=mb_strtolower(trim((string)$primary));
                if(!in_array($primary,['0','1','có','không'],true))$this->fail('Liên hệ chính chỉ nhận Có/Không hoặc 1/0.');
                $incoming['is_primary']=in_array($primary,['1','có'],true);
            }else $incoming['is_primary']=null;
            if($automatic && $link)$raw['source_revision']=(int)$link->source_revision+(hash_equals($link->source_hash,ImportRevision::hash($incoming))?0:1);
            $plan=ImportRevision::plan($link?(array)$link:null,(int)$raw['source_revision'],$incoming,$contact?$this->snapshot($contact):[],true);
            $values=array_intersect_key($plan['values'],array_flip(['customer_id','name','phone','email','position','is_primary']));
            if(($values['is_primary'] ?? false)===true){
                if(isset($primaryCustomers[$customer->id]))$this->fail('Một khách hàng không được có hai liên hệ chính trong cùng file.');
                $primaryCustomers[$customer->id]=true;
            }
            Validator::make($values,['name'=>'required|string|max:255','phone'=>'nullable|string|max:50','email'=>'nullable|email|max:255','position'=>'nullable|string|max:255'],ImportValidation::MESSAGES,self::LABELS)->validate();
            $keys=$this->keys($values);if(!$keys)$this->fail('Cần điện thoại hoặc email để kiểm tra trùng.');
            foreach($keys as $key){$key=$customer->id.':'.$key;if(isset($seenKeys[$key]))$this->fail('Điện thoại hoặc email trùng người liên hệ khác trong file.');$seenKeys[$key]=true;}
            foreach(CustomerContact::where('customer_id',$customer->id)->when($contact,fn($q)=>$q->whereKeyNot($contact->id))->get(['phone','email']) as $other)if(array_intersect($keys,$this->keys($other->toArray())))$this->fail('Điện thoại hoặc email đã thuộc người liên hệ khác của khách hàng. Không tự ghép.');
            $plans[]=['row'=>$row['row'],'external_id'=>$external,'revision'=>(int)$raw['source_revision'],'customer'=>$customer,'contact'=>$contact,'plan'=>$plan,'values'=>$values];
        }catch(ValidationException $e){$errors[]=['row'=>$row['row'],'message'=>implode(' ',array_merge(...array_values($e->errors())))];}}
        $preview=array_map(fn($p)=>['row'=>$p['row'],'external_id'=>$p['external_id'],'source_revision'=>$p['revision'],'action'=>$p['plan']['action'],'changes'=>$p['plan']['changes'],'customer_name'=>$p['customer']->name,'customer_code'=>$p['customer']->code,'crm_hash'=>$p['contact']?ImportRevision::hash($this->snapshot($p['contact'])):null]+$p['values'],$plans);
        $imported=0;if(!$errors && $confirm){foreach($plans as $p){
            if($p['plan']['action']==='skip')continue;
            $data=$p['values'];unset($data['customer_id']);if($data['is_primary']===null)unset($data['is_primary']);
            $child=Request::create('/internal/contact','POST',$data);$child->setUserResolver(fn()=>$actor);$controller=app(CustomerController::class);
            $response=$p['contact']?$controller->updateContact($child,$p['customer'],$p['contact']):$controller->storeContact($child,$p['customer']);
            if($response->getStatusCode()>=300)$this->fail('Không thể lưu dòng '.$p['row'].'. Chưa ghi lô nhập.');
            $contact=CustomerContact::findOrFail($response->getData(true)['data']['id']);
            if($p['external_id']===''){$imported++;continue;}
            $identity=['tenant_id'=>$actor->tenant_id,'source_id'=>$sourceId,'catalog'=>'contacts','external_id'=>$p['external_id']];
            $mapping=['record_id'=>$contact->id,'source_revision'=>$p['revision'],'source_hash'=>$p['plan']['source_hash'],'crm_hash'=>ImportRevision::hash($this->snapshot($contact)),'updated_at'=>now()];
            if($p['contact'])DB::table('import_record_links')->where($identity)->update($mapping);else DB::table('import_record_links')->insert($identity+$mapping+['created_at'=>now()]);$imported++;
        }}
        return ['total'=>count($rows),'imported'=>$imported,'skipped'=>count(array_filter($plans,fn($p)=>$p['plan']['action']==='skip')),'can_import'=>!$errors,'errors'=>$errors,'preview'=>$preview];
    }
}
