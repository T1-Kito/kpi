<?php
namespace App\Support;

use App\Models\{Department,Position,User};
use App\Http\Controllers\Api\V1\{DepartmentController,PositionController};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema,Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Plans the whole batch before writes, including the final department graph. */
final class OrganizationSourceImport
{
    private const TECH=['schema_version'=>'Phiên bản mẫu','external_id'=>'Mã ngoài','source_revision'=>'Phiên bản nguồn'];
    public const FIELDS=[
        'positions'=>['name'=>'Tên chức vụ'],
        'departments'=>['name'=>'Tên phòng ban','parent_ref'=>'Phòng ban cha','manager_ref'=>'Trưởng phòng'],
        'users'=>['name'=>'Họ tên','email'=>'Email','department_ref'=>'Phòng ban','position_ref'=>'Chức vụ','manager_ref'=>'Người quản lý'],
    ];
    public static function labels(string $kind):array{return self::TECH+self::FIELDS[$kind];}
    private function fail(string $message):never{throw ValidationException::withMessages(['file'=>$message]);}
    private function query(string $kind,int $tenant){return match($kind){'positions'=>Position::where('tenant_id',$tenant),'departments'=>Department::where('tenant_id',$tenant),'users'=>User::where('tenant_id',$tenant)};}
    private function snapshot($item,string $kind):array{
        $fields=match($kind){'positions'=>['name'],'departments'=>['name','parent_id','manager_id'],'users'=>['name','email','department_id','position_id','manager_id','is_active']};
        $values=$item->only($fields)+['_updated_at'=>$item->getRawOriginal('updated_at')];
        if($kind==='users')$values['_roles']=$item->roles()->orderBy('roles.id')->pluck('roles.id')->all();
        return $values;
    }
    private function reference($actor,int $source,string $kind,?string $value):?int{
        if(blank($value))return null;
        if(!preg_match('/^(crm|ext):(.+)$/D',trim($value),$m)){
            if($kind==='users' && filter_var($value,FILTER_VALIDATE_EMAIL))$m=[null,'crm',$value];
            else $this->fail('Liên kết cần chọn từ danh sách hoặc dùng crm:Mã-quản-lý / ext:Mã-ngoài đã nhập trước.');
        }
        $query=$this->query($kind,$actor->tenant_id);
        if($kind==='users')$query->where('is_active',true)->whereDoesntHave('roles',fn($q)=>$q->where('code',SystemAccount::ROLE));
        if($m[1]==='crm')$query->where($kind==='users'?'email':'code',$m[2]);
        else $query->whereKey(DB::table('import_record_links')->where('tenant_id',$actor->tenant_id)->where('source_id',$source)->where('catalog',$kind)->where('external_id',strtolower($m[2]))->value('record_id'));
        $item=$query->lockForUpdate()->first();
        if(!$item)$this->fail('Không tìm thấy '.(['users'=>'người đang hoạt động','departments'=>'phòng ban','positions'=>'chức vụ'][$kind]).' trong công ty. Nhập hồ sơ được tham chiếu trước.');
        return $item->id;
    }
    public function run(Request $request,string $path,int $source,bool $confirm,string $kind):array{
        if(DB::connection()->transactionLevel()<1)throw new \LogicException('Nhập tổ chức cần giao dịch lô.');
        abort_unless(Schema::hasTable('import_record_links'),503,'Chưa nâng cấp liên kết nguồn nhập.');
        $actor=$request->user();$labels=self::labels($kind);$rows=(new CustomerImportWorkbook($labels,['external_id']))->read($path);
        $plans=[];$errors=[];$seen=[];$seenBusiness=[];$existing=[];
        $identity=$kind==='users'?'email':'name';
        // Email uniqueness is global in the login table; do not expose another tenant's identity.
        $query=$kind==='users'?User::query():$this->query($kind,$actor->tenant_id);
        foreach($query->get(['id',$identity]) as $item)$existing[mb_strtolower(trim($item->$identity))][]=$item->id;
        foreach($rows as $row)try{
            $raw=$row['data'];$automatic=!array_key_exists('source_revision',$raw);$raw['schema_version']??='1';$raw['source_revision']??='1';
            Validator::make($raw,['schema_version'=>'required|in:1','external_id'=>'nullable|string|max:100|regex:/^[A-Za-z0-9_.-]+$/','source_revision'=>'required|integer|min:1|max:2147483647'],ImportValidation::MESSAGES,$labels)->validate();
            $external=strtolower(trim($raw['external_id'] ?? ''));
            if($external!=='' && isset($seen[$external]))$this->fail('Mã ngoài bị trùng trong file.');
            if($external!=='')$seen[$external]=true;
            $link=$external!==''?DB::table('import_record_links')->where('tenant_id',$actor->tenant_id)->where('source_id',$source)->where('catalog',$kind)->where('external_id',$external)->first():null;
            $item=$link?$this->query($kind,$actor->tenant_id)->whereKey($link->record_id)->lockForUpdate()->first():null;
            if($link && !$item)$this->fail('Hồ sơ đã liên kết không còn tồn tại. Không tự ghép hồ sơ khác.');
            if($kind==='users' && $item && $item->isSpAdmin())$this->fail('Không được nhập hoặc thay đổi tài khoản Chủ tịch qua Excel.');
            $values=['name'=>$raw['name'] ?? null];
            if($kind==='departments')$values+=['parent_id'=>$this->reference($actor,$source,'departments',$raw['parent_ref'] ?? null),'manager_id'=>$this->reference($actor,$source,'users',$raw['manager_ref'] ?? null)];
            if($kind==='users')$values+=['email'=>filled($raw['email'] ?? null)?mb_strtolower(trim($raw['email'])):null,'department_id'=>$this->reference($actor,$source,'departments',$raw['department_ref'] ?? null),'position_id'=>$this->reference($actor,$source,'positions',$raw['position_ref'] ?? null),'manager_id'=>$this->reference($actor,$source,'users',$raw['manager_ref'] ?? null),'is_active'=>false];
            $current=$item?$this->snapshot($item,$kind):[];
            $revision=$automatic && $link?(int)$link->source_revision+(hash_equals($link->source_hash,ImportRevision::hash($values))?0:1):(int)$raw['source_revision'];
            $plan=ImportRevision::plan($link?(array)$link:null,$revision,$values,$current,$kind!=='users');
            $final=$plan['values'];$rules=['name'=>'required|string|max:255'];if($kind==='users')$rules['email']='required|email|max:255';
            Validator::make($final,$rules,ImportValidation::MESSAGES,$labels)->validate();
            $key=mb_strtolower(trim($final[$identity]));
            if(isset($seenBusiness[$key]) || array_diff($existing[$key] ?? [],$item?[$item->id]:[]))$this->fail(($kind==='users'?'Email':'Tên').' đã tồn tại hoặc trùng trong file. Không tự ghép hồ sơ.');
            $seenBusiness[$key]=true;
            $plans[]=compact('external','revision','item','plan')+['row'=>$row['row']];
        }catch(ValidationException $e){$errors[]=['row'=>$row['row'],'message'=>implode(' ',array_merge(...array_values($e->errors())))];}
        if($kind==='departments'){
            $graph=Department::where('tenant_id',$actor->tenant_id)->pluck('parent_id','id')->all();
            foreach($plans as $p)if($p['item'])$graph[$p['item']->id]=$p['plan']['values']['parent_id'];
            foreach($plans as $p){
                $visited=[];$id=$p['item']?$p['item']->id:$p['plan']['values']['parent_id'];
                while($id!==null){if(isset($visited[$id])){$errors[]=['row'=>$p['row'],'message'=>'Phòng ban cha tạo vòng lặp. Chưa nhập toàn bộ lô.'];break;}$visited[$id]=true;$id=$graph[$id] ?? null;}
            }
        }
        $preview=array_map(function($p)use($kind,$actor){
            $values=array_filter($p['plan']['values'],fn($key)=>!str_starts_with($key,'_'),ARRAY_FILTER_USE_KEY);$references=[];
            foreach(['parent_id'=>'departments','department_id'=>'departments','position_id'=>'positions','manager_id'=>'users'] as $field=>$catalog)if(!empty($values[$field])){$ref=$this->query($catalog,$actor->tenant_id)->find($values[$field]);$references[]=$ref?($ref->code ?? $ref->email).' · '.$ref->name:'';}
            return ['row'=>$p['row'],'external_id'=>$p['external'],'action'=>$p['plan']['action'],'changes'=>$p['plan']['changes'],'crm_hash'=>$p['item']?ImportRevision::hash($this->snapshot($p['item'],$kind)):null,'reference_label'=>implode(' / ',$references)]+$values;
        },$plans);
        $imported=0;
        if($kind==='departments' && !$errors){
            $depth=function($p)use($graph){$id=$p['plan']['values']['parent_id'];$depth=0;while($id!==null){$depth++;$id=$graph[$id] ?? null;}return $depth;};
            usort($plans,fn($a,$b)=>$depth($a)<=>$depth($b));
        }
        if(!$errors && $confirm)foreach($plans as $p){
            if($p['plan']['action']==='skip')continue;
            $data=array_filter($p['plan']['values'],fn($key)=>!str_starts_with($key,'_'),ARRAY_FILTER_USE_KEY);
            if($kind==='users'){
                $item=User::create($data+['tenant_id'=>$actor->tenant_id,'password'=>Str::random(64)]);
                app(AuditLogger::class)->record('user',$item->id,'import_inactive_user',$actor,null,$item->toArray(),$request);
            }else{
                $child=Request::create('/internal/organization-import','POST',$data);$child->setUserResolver(fn()=>$actor);
                $controller=app($kind==='positions'?PositionController::class:DepartmentController::class);
                $response=$p['item']?$controller->update($child,$p['item']):$controller->store($child);
                $item=$this->query($kind,$actor->tenant_id)->findOrFail($response->getData(true)['data']['id']);
            }
            if($p['external']!==''){
                $identity=['tenant_id'=>$actor->tenant_id,'source_id'=>$source,'catalog'=>$kind,'external_id'=>$p['external']];
                $created=$p['item']?DB::table('import_record_links')->where($identity)->value('created_at'):now();
                DB::table('import_record_links')->updateOrInsert($identity,['record_id'=>$item->id,'source_revision'=>$p['revision'],'source_hash'=>$p['plan']['source_hash'],'crm_hash'=>ImportRevision::hash($this->snapshot($item,$kind)),'created_at'=>$created,'updated_at'=>now()]);
            }$imported++;
        }
        return ['total'=>count($rows),'can_import'=>!$errors,'imported'=>$imported,'skipped'=>count(array_filter($plans,fn($p)=>$p['plan']['action']==='skip')),'errors'=>$errors,'preview'=>$preview];
    }
}
