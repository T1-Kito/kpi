<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{Customer,CustomerContact,Lead,Tenant};
use App\Support\{CustomerImportWorkbook,DataScope};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\ValidationException;

class DirectoryImportController extends Controller
{
    public const CONTACT=['customer_code'=>'Mã khách hàng','name'=>'Tên người liên hệ','phone'=>'Số điện thoại','email'=>'Email','position'=>'Chức vụ'];
    public const LEAD=['name'=>'Tên khách hàng tiềm năng','phone'=>'Số điện thoại','email'=>'Email','source'=>'Nguồn','campaign_code'=>'Mã chiến dịch'];
    private function kind(string $kind):array { abort_unless(in_array($kind,['contacts','leads'],true),404); return $kind==='contacts' ? self::CONTACT : self::LEAD; }
    public function template(string $kind) { $this->kind($kind); return response()->download(resource_path('templates/import/'.$kind.'.xlsx'),$kind==='contacts'?'mau-nguoi-lien-he.xlsx':'mau-khach-tiem-nang.xlsx'); }
    private function phone(?string $value):string { $p=preg_replace('/\D/','',$value ?? ''); return str_starts_with($p,'84') && strlen($p)>=11 ? '0'.substr($p,2) : $p; }
    public function store(Request $request,string $kind,CustomerController $customers,LeadController $leads)
    {
        $labels=$this->kind($kind);
        $request->validate(['file'=>['required','file','max:1024'],'confirm'=>['sometimes','boolean']]);
        if(strtolower($request->file('file')->getClientOriginalExtension())!=='xlsx') throw ValidationException::withMessages(['file'=>'Chọn Excel .xlsx theo mẫu.']);
        $rows=(new CustomerImportWorkbook($labels,['customer_code','phone']))->read($request->file('file')->getRealPath());
        return DB::transaction(function()use($request,$kind,$labels,$rows,$customers,$leads){
            $actor=$request->user(); $errors=[]; $ready=[]; $seen=[]; $existingKeys=[]; $loaded=[];
            if($request->boolean('confirm')) Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
            foreach($rows as $row) {
                $d=array_replace(array_fill_keys(array_keys($labels),null),$row['data']);
                $rules=['name'=>'required|string|max:255','phone'=>($kind==='leads'?'required':'nullable').'|string|max:50','email'=>'nullable|email|max:255'];
                $rules += $kind==='contacts' ? ['customer_code'=>'required|string|max:100','position'=>'nullable|string|max:255'] : ['source'=>'nullable|string|max:100','campaign_code'=>'nullable|string|max:100'];
                $validator=Validator::make($d,$rules);
                if($validator->fails()) { $errors[]=['row'=>$row['row'],'message'=>'Kiểm tra tên, điện thoại, email và độ dài thông tin theo mẫu.']; continue; }
                $phone=$this->phone($d['phone']); $email=mb_strtolower(trim($d['email'] ?? ''));
                if(!$phone && !$email) { $errors[]=['row'=>$row['row'],'message'=>'Cần điện thoại hoặc email để kiểm tra trùng.']; continue; }
                if($kind==='leads' && !$phone) { $errors[]=['row'=>$row['row'],'message'=>'Điện thoại phải chứa chữ số.']; continue; }
                $customer=null;
                if($kind==='contacts') {
                    $customer=DataScope::owned(Customer::where('tenant_id',$actor->tenant_id)->where('code',$d['customer_code'])->where('customer_type','organization')->where('status','active')->whereNull('merged_into_id'),$actor,'sales_owner_id','salesOwner')->first();
                    if(!$customer) { $errors[]=['row'=>$row['row'],'message'=>'Cần mã khách tổ chức đang hoạt động trong phạm vi phụ trách.']; continue; }
                }
                $prefix=$customer ? $customer->id.':' : '';
                $keys=array_filter([$phone ? $prefix.'p:'.$phone : null,$email ? $prefix.'e:'.$email : null]);
                $duplicate=false;
                foreach($keys as $key) { if(isset($seen[$key])) $duplicate=true; $seen[$key]=true; }
                if(!isset($loaded[$prefix])) {
                    $loaded[$prefix]=true;
                    $query=$kind==='contacts' ? CustomerContact::where('customer_id',$customer->id) : Lead::where('tenant_id',$actor->tenant_id);
                    $query->select(['id','phone','email'])->chunkById(500,function($records)use(&$existingKeys,$prefix){
                        foreach($records as $existing) {
                            $p=$this->phone($existing->phone);$e=mb_strtolower(trim($existing->email ?? ''));
                            if($p) $existingKeys[$prefix.'p:'.$p]=true;
                            if($e) $existingKeys[$prefix.'e:'.$e]=true;
                        }
                    });
                }
                foreach($keys as $key) if(isset($existingKeys[$key])) $duplicate=true;
                if($duplicate) { $errors[]=['row'=>$row['row'],'message'=>'Điện thoại hoặc email bị trùng trong file hoặc hệ thống. Không ghi đè.']; continue; }
                if($kind==='contacts') { unset($d['customer_code']); $d['customer_id']=$customer->id; $d['is_primary']=false; }
                else $d['assigned_to']=$actor->id;
                $ready[]=$d;
            }
            $imported=0;
            if(!$errors && $request->boolean('confirm')) foreach($ready as $data) {
                $child=Request::create('/api/v1/'.($kind==='contacts'?'customer-contacts':'leads'),'POST',$data,[],[],$request->server->all());
                $child->setUserResolver(fn()=>$actor);
                $response=$kind==='contacts' ? $customers->storeDirectoryContact($child) : $leads->store($child);
                if($response->getStatusCode()!==201) throw ValidationException::withMessages(['file'=>'Chưa nhập dữ liệu. Kiểm tra lại thông tin.']);
                $imported++;
            }
            return response()->json(['data'=>['total'=>count($rows),'can_import'=>!$errors,'imported'=>$imported,'errors'=>$errors]]);
        });
    }
}
