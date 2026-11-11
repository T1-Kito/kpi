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
    public const LEAD=['name'=>'Tên khách hàng tiềm năng','phone'=>'Số điện thoại','email'=>'Email','source'=>'Nguồn','campaign_code'=>'Mã chiến dịch','needs'=>'Nhu cầu','region'=>'Khu vực','expected_service_date'=>'Ngày dự kiến sử dụng','guest_count'=>'Số khách dự kiến','interested_services'=>'Dịch vụ quan tâm','notes'=>'Ghi chú'];
    private function kind(string $kind):array { abort_unless(in_array($kind,['contacts','leads'],true),404); return $kind==='contacts' ? self::CONTACT : self::LEAD; }
    public function template(string $kind) {
        $this->kind($kind);
        if ($kind==='leads') return response()->download(\App\Support\LeadImportTemplate::build(), 'mau-khach-tiem-nang.xlsx')->deleteFileAfterSend(true);
        return response()->download(resource_path('templates/import/contacts.xlsx'),'mau-nguoi-lien-he.xlsx');
    }
    private function phone(?string $value):string { $p=preg_replace('/\D/','',$value ?? ''); return str_starts_with($p,'84') && strlen($p)>=11 ? '0'.substr($p,2) : $p; }
    public function store(Request $request,string $kind,CustomerController $customers,LeadController $leads)
    {
        $labels=$this->kind($kind);
        $request->validate(['file'=>['required','file','max:1024'],'confirm'=>['sometimes','boolean']]);
        if(strtolower($request->file('file')->getClientOriginalExtension())!=='xlsx') throw ValidationException::withMessages(['file'=>'Chọn Excel .xlsx theo mẫu.']);
        $rows=(new CustomerImportWorkbook($labels,['customer_code','phone'],['expected_service_date']))->read($request->file('file')->getRealPath());
        return DB::transaction(function()use($request,$kind,$labels,$rows,$customers,$leads){
            $actor=$request->user(); $errors=[]; $ready=[]; $seen=[]; $existingKeys=[]; $loaded=[];
            if($request->boolean('confirm')) Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
            foreach($rows as $row) {
                $d=array_replace(array_fill_keys(array_keys($labels),null),$row['data']);
                if($kind==='leads') $d['expected_service_date']=\App\Support\ImportValidation::isoDate($d['expected_service_date']);
                $rules=['name'=>'required|string|max:255','phone'=>($kind==='leads'?'required':'nullable').'|string|max:50|phone_number','email'=>'nullable|email|max:255'];
                $rules += $kind==='contacts' ? ['customer_code'=>'required|string|max:100','position'=>'nullable|string|max:255'] : ['source'=>'nullable|string|max:100','campaign_code'=>'nullable|string|max:100'];
                if ($kind==='leads') $rules += ['needs'=>'nullable|string|max:4000','region'=>'nullable|string|max:255','expected_service_date'=>'nullable|date_format:Y-m-d','guest_count'=>'nullable|integer|min:1|max:1000000','interested_services'=>'nullable|string|max:2000','notes'=>'nullable|string|max:5000'];
                $validator=Validator::make($d,$rules,\App\Support\ImportValidation::MESSAGES + ['date_format'=>':attribute phải có dạng năm-tháng-ngày, ví dụ 2026-11-15.'],$labels);
                if($validator->fails()) { $errors[]=['row'=>$row['row'],'message'=>implode(' ', $validator->errors()->all())]; continue; }
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
                $duplicateRows=[];
                foreach($keys as $key) { if(isset($seen[$key])) { $duplicate=true; $duplicateRows[]=$seen[$key]; } else $seen[$key]=$row['row']; }
                if(!isset($loaded[$prefix])) {
                    $loaded[$prefix]=true;
                    $query=$kind==='contacts' ? CustomerContact::where('customer_id',$customer->id) : \App\Support\LeadWorkflow::visible(Lead::where('tenant_id',$actor->tenant_id),$actor);
                    $query->select($kind==='leads'?['id','code','name','phone','email']:['id','name','phone','email'])->chunkById(500,function($records)use(&$existingKeys,$prefix){
                        foreach($records as $existing) {
                            $p=$this->phone($existing->phone);$e=mb_strtolower(trim($existing->email ?? ''));
                            $label=($existing->code ? $existing->code.' — ' : '').$existing->name;
                            if($p) $existingKeys[$prefix.'p:'.$p]=$label;
                            if($e) $existingKeys[$prefix.'e:'.$e]=$label;
                        }
                    });
                }
                $duplicateRecords=[];
                foreach($keys as $key) if(isset($existingKeys[$key])) { $duplicate=true;$duplicateRecords[]=$existingKeys[$key]; }
                if($duplicate) { $errors[]=['row'=>$row['row'],'message'=>($duplicateRows ? 'Trùng điện thoại/email với dòng '.implode(', ',array_unique($duplicateRows)).' trong file. ' : '').($duplicateRecords ? 'Đã có ở hồ sơ '.implode('; ',array_unique($duplicateRecords)).'. ' : '').'Kiểm tra trùng trước khi nhập; không ghi đè.']; continue; }
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
