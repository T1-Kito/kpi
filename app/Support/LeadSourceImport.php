<?php
namespace App\Support;

use App\Models\{Lead,User};
use App\Http\Controllers\Api\V1\LeadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema,Validator};
use Illuminate\Validation\ValidationException;

/** Leads are create-only. Existing mapped leads may only be replayed unchanged. */
final class LeadSourceImport
{
    public const LABELS=['schema_version'=>'Phiên bản mẫu','external_id'=>'Mã ngoài','source_revision'=>'Phiên bản nguồn','name'=>'Tên khách hàng tiềm năng','phone'=>'Số điện thoại','email'=>'Email','source'=>'Nguồn','campaign_code'=>'Mã chiến dịch','assigned_to_ref'=>'Người phụ trách','customer_ref'=>'Mã khách hàng'];
    private function snapshot(Lead $lead):array {
        return $lead->only(['name','phone','email','source','campaign_code','assigned_to','customer_id','status'])+['_updated_at'=>$lead->getRawOriginal('updated_at')];
    }
    private function keys(array $d):array {
        $p=preg_replace('/\D/','',$d['phone'] ?? '');if(str_starts_with($p,'84') && strlen($p)>=11)$p='0'.substr($p,2);
        return array_filter([$p?'p:'.$p:null,filled($d['email'] ?? null)?'e:'.mb_strtolower(trim($d['email'])):null]);
    }
    public function run(Request $request,string $path,int $sourceId,bool $confirm):array {
        if(DB::connection()->transactionLevel()<1)throw new \LogicException('Nhập khách tiềm năng cần giao dịch lô.');
        abort_unless(Schema::hasTable('import_record_links'),503,'Chưa nâng cấp liên kết nguồn nhập.');
        $rows=(new CustomerImportWorkbook(self::LABELS,['external_id','phone']))->read($path);
        $actor=$request->user();$plans=[];$errors=[];$seen=[];$seenKeys=[];
        $existing=[];foreach(Lead::where('tenant_id',$actor->tenant_id)->select(['id','phone','email'])->cursor() as $lead)foreach($this->keys($lead->toArray()) as $key)$existing[$key][]=$lead->id;
        foreach($rows as $row){try{
$raw=$row['data'];$raw['schema_version']??='1';$raw['source_revision']??='1';Validator::make($raw,['schema_version'=>'required|in:1','external_id'=>'nullable|string|max:100|regex:/^[A-Za-z0-9_.-]+$/','source_revision'=>'required|integer|min:1|max:2147483647','name'=>'required|string|max:255','phone'=>'required|string|max:50','email'=>'nullable|email|max:255','source'=>'nullable|string|max:100','campaign_code'=>'nullable|string|max:100','assigned_to_ref'=>'nullable|string|max:255','customer_ref'=>'nullable|string|max:150'],ImportValidation::MESSAGES,self::LABELS)->validate();
            $external=strtolower(trim($raw['external_id'] ?? ''));if($external!==''){if(isset($seen[$external]))throw ValidationException::withMessages(['file'=>'Mã ngoài bị trùng trong file.']);$seen[$external]=true;}
            $values=[];foreach(['name','phone','email','source','campaign_code'] as $field)$values[$field]=$raw[$field] ?? null;$values['source']??='manual';
            $assigned=$actor->id;$customerId=null;
            if(filled($raw['assigned_to_ref'] ?? null)){
                $email=preg_replace('/^crm:/i','',trim($raw['assigned_to_ref']));
                $owner=User::where('tenant_id',$actor->tenant_id)->where('is_active',true)->where('email',$email)->first();
                if(!$owner)throw ValidationException::withMessages(['assigned_to_ref'=>'Không tìm thấy người phụ trách đang hoạt động. Điền email người dùng trong công ty.']);
                $assigned=$owner->id;
            }
            if(filled($raw['customer_ref'] ?? null)){
                if(!$actor->hasPermission('customer.manage'))throw ValidationException::withMessages(['customer_ref'=>'Bạn chưa có quyền sử dụng danh mục khách hàng. Để trống cột Mã khách hàng hoặc liên hệ quản trị.']);
                $customerId=app(ImportCustomerReference::class)->resolve($actor,$sourceId,$raw['customer_ref'])->id;
            }
            $references=['assigned_to'=>$assigned,'customer_id'=>$customerId];
            if(!preg_match('/\d/',$values['phone']))throw ValidationException::withMessages(['file'=>'Điện thoại phải chứa chữ số.']);
            $link=DB::table('import_record_links')->where('tenant_id',$actor->tenant_id)->where('source_id',$sourceId)->where('catalog','leads')->where('external_id',$external)->first();
            $lead=$link?Lead::where('tenant_id',$actor->tenant_id)->whereKey($link->record_id)->lockForUpdate()->first():null;
            if($link && !$lead)throw ValidationException::withMessages(['file'=>'Khách tiềm năng liên kết không còn tồn tại. Không tự nhận hồ sơ khác.']);
            $hashValues=$values;
            if(array_key_exists('assigned_to_ref',$raw) || array_key_exists('customer_ref',$raw))$hashValues+=$references;
            $plan=ImportRevision::plan($link?(array)$link:null,(int)$raw['source_revision'],$hashValues,$lead?$this->snapshot($lead):[],false);
            foreach($this->keys($values) as $key){
                if(isset($seenKeys[$key]) || array_diff($existing[$key] ?? [],$lead?[$lead->id]:[]))throw ValidationException::withMessages(['file'=>'Điện thoại hoặc email trùng trong file hoặc hệ thống. Không tự ghép hoặc ghi đè.']);
                $seenKeys[$key]=true;
            }
            $plans[]=['row'=>$row['row'],'external_id'=>$external,'revision'=>(int)$raw['source_revision'],'values'=>$values,'references'=>$references,'plan'=>$plan,'lead'=>$lead];
        }catch(ValidationException $e){$errors[]=['row'=>$row['row'],'message'=>implode(' ',array_merge(...array_values($e->errors())))];}}
        $preview=array_map(fn($p)=>['row'=>$p['row'],'external_id'=>$p['external_id'],'source_revision'=>$p['revision'],'action'=>$p['plan']['action'],'changes'=>[],'crm_hash'=>$p['lead']?ImportRevision::hash($this->snapshot($p['lead'])):null]+$p['values']+$p['references'],$plans);
        $imported=0;if(!$errors && $confirm)foreach($plans as $p){
            if($p['plan']['action']==='skip')continue;
            $child=Request::create('/internal/lead','POST',$p['values']+$p['references']);$child->setUserResolver(fn()=>$actor);
            $response=app(LeadController::class)->store($child);if($response->getStatusCode()!==201)throw ValidationException::withMessages(['file'=>'Không thể tạo khách tiềm năng. Chưa ghi toàn lô.']);
            $lead=Lead::where('tenant_id',$actor->tenant_id)->findOrFail($response->getData(true)['data']['id']);
            if($p['external_id']===''){$imported++;continue;}
            DB::table('import_record_links')->insert(['tenant_id'=>$actor->tenant_id,'source_id'=>$sourceId,'catalog'=>'leads','external_id'=>$p['external_id'],'record_id'=>$lead->id,'source_revision'=>$p['revision'],'source_hash'=>$p['plan']['source_hash'],'crm_hash'=>ImportRevision::hash($this->snapshot($lead)),'created_at'=>now(),'updated_at'=>now()]);$imported++;
        }
        return ['total'=>count($rows),'can_import'=>!$errors,'imported'=>$imported,'skipped'=>count(array_filter($plans,fn($p)=>$p['plan']['action']==='skip')),'errors'=>$errors,'preview'=>$preview];
    }
}
