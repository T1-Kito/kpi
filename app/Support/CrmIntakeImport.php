<?php

namespace App\Support;

use App\Models\{Customer,CustomerContact,Deal,ServiceInterest,User};
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Crypt,DB,Validator};
use Illuminate\Validation\ValidationException;

/** Plans without writes; commit reruns the same plan under the tenant lock. */
final class CrmIntakeImport
{
    public static function schema(Blueprint $t):void
    {
        $t->id();$t->unsignedBigInteger('tenant_id');$t->unsignedBigInteger('user_id');
        $t->string('source_key',64);$t->string('source_name',150);$t->string('external_ref',100)->nullable();
        $t->string('identity_hash',64);$t->string('content_hash',64);
        $t->unsignedBigInteger('customer_id');$t->unsignedBigInteger('deal_id')->nullable();
        $t->longText('encrypted_payload');$t->timestamps();
        $t->unique(['tenant_id','source_key','identity_hash'],'crm_intake_identity_unique');
        $t->index(['tenant_id','source_key','external_ref'],'crm_intake_reference_index');
    }

    public function snapshot(User $actor):string
    {
        $state=[];
        foreach(['customers','users','customer_groups','service_interests','sales_pipeline_stages','crm_intake_rows'] as $table)
            $state[$table]=DB::table($table)->where('tenant_id',$actor->tenant_id)->orderBy('id')->get()->toArray();
        $state['contacts']=DB::table('customer_contacts')->whereIn('customer_id',DB::table('customers')->where('tenant_id',$actor->tenant_id)->select('id'))->orderBy('id')->get()->toArray();
        $state['roles']=$actor->fresh()->roles()->with('permissions')->get()->toArray();
        return self::hash($state);
    }

    public function plan(User $actor,array $rows,string $sourceName):array
    {
        $sourceKey=self::hash(self::norm($sourceName));
        $customers=[];$index=[];$external=[];$seen=[];$plans=[];
        foreach(Customer::withoutGlobalScope('catalog_visibility')->where('tenant_id',$actor->tenant_id)->whereNull('merged_into_id')->get() as $c){
            $ref=(string)$c->id;$customers[$ref]=$c->toArray();$this->index($index,$c->toArray(),$ref);
        }
        $contacts=CustomerContact::whereIn('customer_id',array_keys($customers))->get()->toBase();
        foreach($contacts as $contact)$this->index($index,['phone'=>$contact->phone,'email'=>$contact->email],(string)$contact->customer_id);
        $receipts=DB::table('crm_intake_rows')->where('tenant_id',$actor->tenant_id)->where('source_key',$sourceKey)->get();
        foreach($receipts as $r)if($r->external_ref)$external[self::norm($r->external_ref)][(string)$r->customer_id]=true;
        $users=User::where('tenant_id',$actor->tenant_id)->where('is_active',true)->with('roles.permissions')->get();
        foreach($users as $user)$user->snapshotAuthorizationForRequest();
        $services=ServiceInterest::where('tenant_id',$actor->tenant_id)->get();
        foreach($rows as $row){
            $d=$row['data'];$warnings=[];
            try {
                if($row['errors'] ?? [])self::fail(implode(' ',array_unique($row['errors'])));
                $this->validate($d);
                $name=$d['company_name'] ?: $d['person_name'];$type=$d['company_name']?'organization':'person';
                if(!$name)self::fail('Điền Tên khách hàng hoặc Tên đơn vị. Không lấy tên người liên hệ làm tên đơn vị.');
                if(!$d['phone'] && !$d['email'] && !$d['tax_code'])self::fail('Cần điện thoại, email hoặc mã số thuế để đối chiếu khách hàng.');
                $owner=$this->owner($actor,$users,$d['owner']);
                $matches=[];
                if(str_starts_with(mb_strtolower($d['external_ref']),'crm:')){
                    $code=substr($d['external_ref'],4);
                    foreach($customers as $ref=>$c)if(self::norm($c['code'] ?? '')===self::norm($code))$matches[$ref]=true;
                    if(!$matches)self::fail('Mã KH bắt đầu bằng crm: nhưng không tìm thấy mã khách hàng tương ứng.');
                }elseif($d['external_ref'])$matches=$external[self::norm($d['external_ref'])] ?? [];
                foreach($this->keys($d) as $key)foreach($index[$key] ?? [] as $ref=>$unused)$matches[$ref]=true;
                if(count($matches)>1)self::fail('Thông tin nhận diện trỏ tới nhiều hồ sơ. Kiểm tra Mã KH, mã số thuế, điện thoại và email; không tự gộp.');
                $ref=$matches?(string)array_key_first($matches):'new:'.$row['row'];
                $existing=$customers[$ref] ?? null;
                if($existing && !str_starts_with($ref,'new:')){
                    if(!CatalogAccess::visible(Customer::withoutGlobalScope('catalog_visibility'),$actor)->whereKey($ref)->exists())self::fail('Có thông tin trùng hồ sơ ngoài phạm vi được dùng. Nhờ quản trị kiểm tra, không tạo bản trùng.');
                    if(($existing['status'] ?? '')!=='active')self::fail('Khách hàng đã ngừng sử dụng. Nhờ quản trị kiểm tra trước khi nhập.');
                }
                if($existing && (self::norm($existing['name'])!==self::norm($name) || $existing['customer_type']!==$type))self::fail('Thông tin nhận diện khớp nhưng tên hoặc loại khách khác. Kiểm tra lại, không tự ghi đè hồ sơ.');
                $groupId=CustomerGroupReference::resolve(['group_level1'=>$d['group1'],'group_level2'=>$d['group2'],'group_level3'=>$d['group3']],(int)$actor->tenant_id);
                if($existing && $groupId && (int)($existing['customer_group_id'] ?? 0)!==$groupId)$warnings[]='Giữ phân nhóm trên khách đã có; nhóm trong file được lưu trong thông tin lần nhập.';
                $serviceIds=[];$serviceNames=[];$parent=null;
                foreach(['service1','service2'] as $key){
                    if(!$d[$key]){if($key==='service1' && $d['service2'])self::fail('Có Nhóm SP cấp 2 thì phải có Nhóm SP cấp 1.');break;}
                    $found=$services->filter(fn($s)=>(int)$s->parent_id===(int)$parent && self::norm($s->name)===self::norm($d[$key]));
                    if($found->count()!==1 || !$found->first()->is_active)self::fail('Dịch vụ “'.$d[$key].'” chưa có duy nhất trong đúng nhánh hoặc đã ngừng dùng. Nhập danh mục dịch vụ trước.');
                    $parent=$found->first()->id;
                }
                if($parent){$serviceIds=[$parent];$serviceNames=[implode(' / ',array_filter([$d['service1'],$d['service2']]))];}
                if($d['interests'])$warnings[]='Sản phẩm quan tâm dạng mô tả được giữ nguyên; không tự tạo SKU hay dòng báo giá.';
                $hasNeed=(bool)array_filter(array_intersect_key($d,array_flip(['service1','service2','interests','purpose','group_size','guest_count','service_date','budget','amount','special_requests'])),fn($v)=>$v!=='');
                $stage=$this->stage($actor,$d['crm_status'],$warnings);
                $probability=$this->probability($d['probability']);
                if($probability===null)$probability=(float)(DealService::stages((int)$actor->tenant_id)[$stage] ?? 0);
                $forecast=$d['amount']===''?null:round((float)$d['amount']*$probability/100,2);
                if($d['forecast']!=='' && $forecast!==null && abs((float)$d['forecast']-$forecast)>0.01)$warnings[]='Doanh thu dự kiến trong file khác phép tính; hệ thống tính lại theo giá trị × xác suất.';
                $dates=[];
                foreach(['created_date','service_date','last_contact','next_contact'] as $key)$dates[$key]=$this->date($d[$key],CrmIntakeWorkbook::LABELS[$key]);
                if($dates['last_contact'] && CarbonImmutable::parse($dates['last_contact'])->isFuture())self::fail('Ngày liên hệ gần nhất không được ở tương lai.');
                if($dates['created_date'] && CarbonImmutable::parse($dates['created_date'])->startOfDay()->greaterThan(CarbonImmutable::today(config('app.timezone'))))self::fail('Ngày tạo hồ sơ trong nguồn không được ở tương lai.');
                if($dates['last_contact'] && $dates['next_contact'] && $dates['next_contact']<$dates['last_contact'])self::fail('Ngày liên hệ tiếp theo không được trước ngày liên hệ gần nhất.');
                if($dates['next_contact'] && CarbonImmutable::parse($dates['next_contact'])->isPast())$warnings[]='Ngày liên hệ tiếp theo đã qua; việc chăm sóc sẽ giữ đúng hạn cũ để xử lý.';
                if(!$hasNeed && $dates['next_contact'] && (int)$owner->id!==(int)$actor->id && !CatalogAccess::full($owner)){
                    $ownerCanSee=$existing && !str_starts_with($ref,'new:') && CatalogAccess::visible(Customer::withoutGlobalScope('catalog_visibility'),$owner)->whereKey($ref)->exists();
                    if(!$ownerCanSee)self::fail('Người nhận việc chưa được xem hồ sơ khách riêng tư này. Chọn người có quyền xem; hoặc tạo hồ sơ trước, bật Dùng chung rồi nhập lại lịch chăm sóc.');
                }
                if(!$hasNeed && $d['crm_status'])$warnings[]='Dòng chưa có nhu cầu cụ thể: chỉ lưu hồ sơ và trạng thái gốc, không tạo cơ hội.';
                if($existing)$warnings[]='Dùng lại khách, không đổi tên, người phụ trách, phân nhóm hay bật Dùng chung trên hồ sơ khách.';
                $customerData=['name'=>$name,'customer_type'=>$type,'phone'=>$d['phone'] ?: null,'email'=>$d['email'] ?: null,'tax_code'=>$d['tax_code'] ?: null,'address'=>$d['province'] ?: null,'contact_name'=>$d['contact_name'] ?: ($type==='person'?$d['person_name']:null),'customer_group_id'=>$groupId,'sales_owner_id'=>$owner->id,'status'=>'active'];
                if(!$existing){$customers[$ref]=$customerData;$this->index($index,$customerData,$ref);}
                else $this->index($index,['phone'=>$d['phone'],'email'=>$d['email']],$ref);
                if($d['external_ref'])$external[self::norm($d['external_ref'])][$ref]=true;
                // A stable need identity catches revised reuploads rather than creating another trip.
                $entity=$customers[$ref];
                $customerKey=!empty($entity['tax_code'])?'tax:'.self::digits($entity['tax_code']):(!empty($entity['email'])?'email:'.self::norm($entity['email']):'phone:'.self::digits($entity['phone']));
                $identity=self::hash([$customerKey,$hasNeed,$dates['service_date']?substr($dates['service_date'],0,10):null,self::norm($d['purpose']),self::norm($d['service1']),self::norm($d['service2']),self::norm($d['interests']),$hasNeed?'':self::norm($d['contact_name'])]);
                $normalized=$d;foreach($dates as $k=>$v)$normalized[$k]=$v;$normalized['forecast']=$forecast;$normalized['probability']=$probability;
                $content=self::hash($normalized);$prior=$receipts->firstWhere('identity_hash',$identity);$action=$hasNeed?'opportunity':'customer';
                if(isset($seen[$identity])){
                    if($seen[$identity]!==$content)self::fail('Cùng khách và cùng nhu cầu xuất hiện nhiều dòng nhưng dữ liệu khác nhau. Gộp hoặc sửa lại trước khi nhập.');
                    $action='skip';$warnings[]='Dòng lặp trong file; chỉ nhập lần đầu.';
                }elseif($prior){
                    if(!isset($customers[(string)$prior->customer_id]) || ($prior->deal_id && !DataScope::owned(Deal::where('tenant_id',$actor->tenant_id)->whereKey($prior->deal_id),$actor,'owner_id','owner')->exists()))self::fail('Lần nhập trước không còn trong phạm vi được dùng. Nhờ quản trị kiểm tra.');
                    if($prior->content_hash!==$content)self::fail('Nhu cầu này đã được nhập nhưng nội dung đã đổi. Mở hồ sơ/cơ hội để chỉnh sửa; không nhập thành cơ hội trùng.');
                    $action='skip';$warnings[]='Đã nhập từ nguồn này; không tạo thêm khách, cơ hội hay việc chăm sóc.';
                }
                $seen[$identity]=$content;
                $contactName=$d['contact_name'] ?: ($type==='person'?$d['person_name']:'');
                $this->checkContact($contacts,$ref,$contactName,$d);
                if($contactName)$contacts->push((object)['customer_id'=>$ref,'name'=>$contactName,'phone'=>$d['phone'],'email'=>$d['email']]);
                $plans[]=['row'=>$row['row'],'action'=>$action,'customer_action'=>$existing?'reuse':'create','customer_name'=>$name,'customer_type_label'=>$type==='organization'?'Tổ chức / doanh nghiệp':'Cá nhân','customer_code'=>$existing['code'] ?? null,'owner_name'=>$owner->name,'stage'=>$stage,'probability'=>$probability,'forecast'=>$forecast,'next_contact'=>$dates['next_contact'],'values'=>$d,'warnings'=>$warnings,'errors'=>[],
                    '_ref'=>$ref,'_customer'=>$customerData,'_owner'=>$owner->id,'_identity'=>$identity,'_content'=>$content,'_dates'=>$dates,'_services'=>$serviceIds,'_service_names'=>$serviceNames,'_data'=>$d];
            }catch(ValidationException $e){$messages=[];foreach($e->errors() as $values)$messages=array_merge($messages,$values);$plans[]=['row'=>$row['row'],'action'=>'error','customer_name'=>$d['company_name'] ?: $d['person_name'],'values'=>$d,'warnings'=>$warnings,'errors'=>array_values(array_unique($messages))];}
        }
        return ['rows'=>$plans,'source_key'=>$sourceKey,'source_name'=>$sourceName,'errors'=>count(array_filter($plans,fn($p)=>$p['errors']!==[]))];
    }

    public function commit(User $actor,array $plan):array
    {
        $ids=[];$result=['customers_created'=>0,'customers_reused'=>0,'contacts_created'=>0,'opportunities_created'=>0,'tasks_created'=>0,'skipped'=>0];$reused=[];
        foreach($plan['rows'] as $p){
            if($p['action']==='skip'){$result['skipped']++;continue;}
            $ref=$p['_ref'];$d=$p['_data'];
            if(!isset($ids[$ref])){
                if(str_starts_with($ref,'new:')){
                    $c=Customer::create($p['_customer']+['tenant_id'=>$actor->tenant_id,'created_by'=>$actor->id,'is_shared'=>false,'code'=>app(CodeGenerator::class)->next('customers','code','CUS-',fn($q)=>$q->where('tenant_id',$actor->tenant_id))]);
                    $ids[$ref]=$c->id;$result['customers_created']++;
                    app(AuditLogger::class)->record('customer',$c->id,'create_customer_intake',$actor,null,$c->toArray());
                }else {$ids[$ref]=(int)$ref;if(!isset($reused[$ref])){$result['customers_reused']++;$reused[$ref]=true;}}
            }
            $customer=Customer::findOrFail($ids[$ref]);$contactName=$p['_customer']['contact_name'];
            if($contactName){
                $existing=$customer->contacts()->get()->first(fn($c)=>self::norm($c->name)===self::norm($contactName) && (($d['phone'] && self::digits($c->phone)===self::digits($d['phone'])) || ($d['email'] && self::norm($c->email)===self::norm($d['email'])) || (!$d['phone'] && !$d['email'])));
                if(!$existing){
                    $primary=!$customer->contacts()->where('is_primary',true)->exists() && $p['customer_action']==='create';
                    $contact=$customer->contacts()->create(['name'=>$contactName,'phone'=>$d['phone'] ?: null,'email'=>$d['email'] ?: null,'position'=>$d['position'] ?: null,'is_primary'=>$primary]);$result['contacts_created']++;
                    app(AuditLogger::class)->record('customer_contact',$contact->id,'create_contact_intake',$actor,null,$contact->toArray());
                }
            }
            $deal=null;
            if($p['action']==='opportunity'){
                $needs=['purpose'=>$d['purpose'] ?: null,'group_size'=>$d['group_size'] ?: null,'guest_count'=>$d['guest_count']===''?null:(int)$d['guest_count'],'service_date'=>$p['_dates']['service_date']?substr($p['_dates']['service_date'],0,10):null,'budget'=>$d['budget']===''?null:(float)$d['budget'],'special_requests'=>$d['special_requests'] ?: null,'service_ids'=>$p['_services'],'interests'=>$p['_service_names'],'intake'=>$d];
                $deal=app(DealService::class)->create($actor,['customer_id'=>$customer->id,'owner_id'=>$p['_owner'],'name'=>mb_substr(($d['purpose'] ?: 'Nhu cầu').' — '.$customer->name.($needs['service_date']?' · '.$needs['service_date']:''),0,255),'source'=>$d['source'] ?: null,'description'=>$d['note'] ?: null,'amount'=>$d['amount']===''?0:(float)$d['amount'],'needs'=>$needs,'stage'=>$p['stage'],'next_activity_at'=>$p['_dates']['next_contact']]);
                $deal->update(['probability'=>$p['probability']]);$result['opportunities_created']++;
            }
            if($p['_dates']['next_contact']){
                app(TaskService::class)->create($actor,['module'=>'sales','task_type'=>$deal?'deal_follow_up':'customer_follow_up','title'=>'Chăm sóc '.($deal?->code ?? $customer->code),'source_type'=>$deal?'Deal':'Customer','source_id'=>$deal?->id ?? $customer->id,'assignee_id'=>$p['_owner'],'priority'=>'normal','due_at'=>$p['_dates']['next_contact']]);$result['tasks_created']++;
            }
            DB::table('crm_intake_rows')->insert(['tenant_id'=>$actor->tenant_id,'user_id'=>$actor->id,'source_key'=>$plan['source_key'],'source_name'=>$plan['source_name'],'external_ref'=>$d['external_ref'] ?: null,'identity_hash'=>$p['_identity'],'content_hash'=>$p['_content'],'customer_id'=>$customer->id,'deal_id'=>$deal?->id,'encrypted_payload'=>Crypt::encryptString(json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'created_at'=>now(),'updated_at'=>now()]);
        }
        app(AuditLogger::class)->record('crm_intake',0,'commit_crm_intake',$actor,null,$result+['source'=>$plan['source_name']]);
        return $result;
    }

    private function validate(array $d):void
    {
        $rules=array_fill_keys(array_keys(CrmIntakeWorkbook::LABELS),'nullable|string|max:255');
        foreach(['external_ref'=>100,'tax_code'=>30,'phone'=>50,'source'=>100,'group_size'=>100,'purpose'=>255,'special_requests'=>2000,'note'=>2000,'interests'=>2000] as $key=>$max)$rules[$key]='nullable|string|max:'.$max;
        foreach(['guest_count','budget','amount','forecast'] as $key)$rules[$key]=$key==='guest_count'?'nullable|numeric|integer|min:1|max:1000000':'nullable|numeric|min:0|max:999999999999';
        $rules['email']='nullable|email|max:255';$rules['tax_code']='nullable|regex:/^[0-9.\s-]+$/|max:30';
        $input=array_map(fn($v)=>$v===''?null:$v,$d);
        // Excel commonly serializes an integer count as "180.0".
        if(isset($input['guest_count']) && is_numeric($input['guest_count']) && (float)$input['guest_count']===(float)(int)$input['guest_count'])$input['guest_count']=(int)$input['guest_count'];
        Validator::make($input,$rules,ImportValidation::MESSAGES+['email'=>':attribute không đúng định dạng.'],CrmIntakeWorkbook::LABELS)->validate();
        if($d['tax_code']!=='' && !preg_match('/[1-9]/',$d['tax_code']))self::fail('Mã số thuế toàn số 0 không hợp lệ. Nếu chưa có hãy để trống.');
        if($d['phone']!=='' && (strlen(self::digits($d['phone']))<8 || !preg_match('/^\+?[0-9 ()\-.]+$/D',$d['phone'])))self::fail('Số điện thoại không hợp lệ.');
        if($d['channel2']!=='' && $d['channel1']==='')self::fail('Có Kênh tiếp cận cấp 2 thì phải có Kênh tiếp cận cấp 1.');
    }
    private function owner(User $actor,$users,string $value):User
    {
        $owners=$users->filter(fn($u)=>$u->hasPermission('sales.deal.view') && ($actor->dataScope()==='company' || (int)$u->id===(int)$actor->id || ($actor->dataScope()==='department' && $actor->department_id && (int)$u->department_id===(int)$actor->department_id)));
        $match=$value===''?$owners->where('id',$actor->id):$owners->filter(fn($u)=>self::norm($u->email)===self::norm(preg_replace('/^crm:/i','',$value)) || self::norm($u->name)===self::norm($value));
        if($match->count()!==1)self::fail('Người phụ trách chưa khớp duy nhất tài khoản đang hoạt động, có quyền và thuộc phạm vi phân công. Điền email tài khoản để tránh trùng tên.');
        return $match->first();
    }
    private function stage(User $actor,string $status,array &$warnings):string
    {
        $map=[''=>'new','tiềm năng mới'=>'new','đã liên hệ'=>'qualified','đang tư vấn'=>'qualified','cơ hội đang mở'=>'qualified','đang đàm phán'=>'qualified','đã chuyển đổi'=>'qualified','mất cơ hội'=>'new'];
        $key=self::norm($status);
        if(!array_key_exists($key,$map))self::fail('Trạng thái CRM “'.$status.'” chưa được ánh xạ. Vui lòng dùng trạng thái trong danh mục của file.');
        if(in_array($key,['đang đàm phán','đã chuyển đổi','mất cơ hội'],true))$warnings[]='Giữ trạng thái gốc “'.$status.'” trong thông tin nhập. Cơ hội mới vẫn mở; cần kiểm tra ngày chốt, báo giá hoặc lý do trước khi chuyển giai đoạn/kết thúc.';
        $stage=$map[$key];$stages=DealService::stages((int)$actor->tenant_id);
        if(!array_key_exists($stage,$stages))self::fail('Giai đoạn cần dùng đã ngừng hoạt động. Nhờ quản trị kiểm tra danh mục giai đoạn bán hàng.');
        $type=DB::table('sales_pipeline_stages')->where('tenant_id',$actor->tenant_id)->where('code',$stage)->value('type');
        if($type && $type!=='open')self::fail('Giai đoạn ánh xạ không phải giai đoạn mở. Nhờ quản trị kiểm tra.');
        return $stage;
    }
    private function probability(string $value):?float
    {
        if($value==='')return null;
        $percent=str_ends_with($value,'%');$n=rtrim($value,'%');
        if(!is_numeric($n))self::fail('Xác suất cần là số từ 0 đến 100 hoặc phần trăm.');
        $n=(float)$n;if(!$percent && $n>0 && $n<=1)$n*=100;
        if($n<0 || $n>100)self::fail('Xác suất phải từ 0 đến 100%.');
        return round($n,2);
    }
    private function date(string $value,string $label):?string
    {
        if($value==='')return null;
        foreach(['!Y-m-d H:i:s','!Y-m-d','!d/m/Y H:i','!d/m/Y'] as $format){
            try{$d=CarbonImmutable::createFromFormat($format,$value,config('app.timezone'));if($d && $d->format(ltrim($format,'!'))===$value)return $d->format('Y-m-d H:i:s');}catch(\Throwable){}
        }
        self::fail($label.' không hợp lệ. Dùng ngày Excel hoặc ngày/tháng/năm.');
    }
    private function checkContact($contacts,string $ref,string $name,array $d):void
    {
        if(!$name)return;
        foreach($contacts->where('customer_id',$ref) as $c){
            $same=($d['phone'] && self::digits($d['phone'])===self::digits($c->phone)) || ($d['email'] && self::norm($d['email'])===self::norm($c->email));
            if($same && self::norm($c->name)!==self::norm($name))self::fail('Điện thoại/email người liên hệ đã gắn tên khác trên khách này. Kiểm tra trước khi thêm liên hệ.');
            if($same && (($d['phone'] && $c->phone && self::digits($d['phone'])!==self::digits($c->phone)) || ($d['email'] && $c->email && self::norm($d['email'])!==self::norm($c->email))))self::fail('Người liên hệ khớp một thông tin nhưng điện thoại/email còn lại khác. Sửa liên hệ trên hồ sơ trước, không tự ghi đè bằng file.');
        }
    }
    private function index(array &$index,array $data,string $ref):void {foreach($this->keys($data) as $key)$index[$key][$ref]=true;}
    private function keys(array $d):array
    {
        $keys=[];foreach(['phone','email','tax_code'] as $k)if(!empty($d[$k]))$keys[]=$k.':'.($k==='email'?self::norm($d[$k]):self::digits($d[$k]));return $keys;
    }
    private static function digits(?string $v):string {return preg_replace('/\D/','',$v ?? '');}
    private static function norm(?string $v):string {return mb_strtolower(trim(preg_replace('/\s+/u',' ',$v ?? '')));}
    private static function hash(mixed $v):string {return hash('sha256',json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
    private static function fail(string $message):never {throw ValidationException::withMessages(['file'=>$message]);}
}
