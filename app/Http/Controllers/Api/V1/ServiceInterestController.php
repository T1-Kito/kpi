<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{ServiceInterest,Tenant};
use App\Support\{CatalogAccess,AuditLogger};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class ServiceInterestController extends Controller
{
    public function import(Request $request){
        abort_unless(CatalogAccess::full($request->user()),403);
        $planner=new \App\Support\CustomerGroupImport;
        if(!$request->filled('token')){
            $request->validate(['file'=>['required','file','max:1024']]);
            abort_unless(strtolower($request->file('file')->getClientOriginalExtension())==='xlsx',422,'Chọn file Excel .xlsx.');
            $rows=app(\App\Support\CustomerGroupWorkbook::class)->read($request->file('file')->getRealPath(),true);
            $groups=ServiceInterest::where('tenant_id',$request->user()->tenant_id)->get();$plan=$planner->plan($rows,$groups);
            $token=\Illuminate\Support\Facades\Crypt::encryptString(json_encode(['kind'=>'service_interests','actor'=>$request->user()->id,'tenant'=>$request->user()->tenant_id,'expires'=>time()+900,'snapshot'=>$planner->snapshot($groups),'rows'=>$rows]));
            return response()->json(['data'=>['token'=>$token,'rows'=>$plan['rows'],'create_count'=>$plan['create_count']]]);
        }
        $request->validate(['token'=>['required','string','max:500000']]);
        try{$payload=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($request->input('token')),true,512,JSON_THROW_ON_ERROR);}catch(\Throwable $e){abort(422,'Bản xem trước không hợp lệ.');}
        abort_unless(($payload['kind'] ?? '')==='service_interests' && (int)$payload['actor']===(int)$request->user()->id && (int)$payload['tenant']===(int)$request->user()->tenant_id,403);
        abort_if($payload['expires']<time(),422,'Bản xem trước hết hạn. Vui lòng kiểm tra lại.');
        return DB::transaction(function()use($request,$payload,$planner){
            $tenant=$request->user()->tenant_id;Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $groups=ServiceInterest::where('tenant_id',$tenant)->lockForUpdate()->get();
            abort_unless(hash_equals($payload['snapshot'],$planner->snapshot($groups)),409,'Danh mục đã thay đổi. Vui lòng xem trước lại.');
            $plan=$planner->plan($payload['rows'],$groups);$ids=[];
            foreach($plan['new'] as $node){$parent=str_starts_with($node['parent'],'new:')?$ids[$node['parent']]:($node['parent'] ?: null);$ids[$node['ref']]=ServiceInterest::create(['tenant_id'=>$tenant,'parent_id'=>$parent,'name'=>$node['name'],'is_active'=>true])->id;}
            app(AuditLogger::class)->record('service_interest',0,'import_service_interests',$request->user(),null,['created'=>count($ids)],$request);
            return response()->json(['data'=>['created'=>count($ids)]]);
        });
    }
    public function index(Request $request){return response()->json(['data'=>ServiceInterest::where('tenant_id',$request->user()->tenant_id)->orderBy('name')->get(),'can_manage'=>CatalogAccess::full($request->user())]);}
    public function save(Request $request,?int $id=null){
        abort_unless(CatalogAccess::full($request->user()),403);
        $data=$request->validate(['name'=>['required','string','max:150'],'parent_id'=>['nullable','integer'],'is_active'=>['required','boolean']],['required'=>'Vui lòng nhập :attribute.','max'=>':attribute tối đa :max ký tự.'],['name'=>'tên dịch vụ']);
        $data['name']=trim($data['name']);
        if($data['name']==='')throw ValidationException::withMessages(['name'=>'Vui lòng nhập tên.']);
        return DB::transaction(function()use($request,$data,$id){
            $tenant=$request->user()->tenant_id;Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $all=ServiceInterest::where('tenant_id',$tenant)->lockForUpdate()->get()->keyBy('id');
            $item=$id?($all[$id] ?? abort(404)):new ServiceInterest(['tenant_id'=>$tenant]);
            $parent=$data['parent_id'] ?? null;
            if($id && (int)$parent!==(int)$item->parent_id)abort(422,'Không chuyển nhánh dịch vụ đã tạo; hãy tạo mục mới.');
            if($parent){$p=$all[$parent] ?? null;abort_unless($p && !$p->parent_id && $p->id!==$id,422,'Nhóm cha không hợp lệ. Danh mục tối đa 2 cấp.');abort_if($data['is_active'] && !$p->is_active,422,'Nhóm cha đã ngừng sử dụng.');}
            foreach($all as $g)if($g->id!=$id && (int)$g->parent_id===(int)$parent && mb_strtolower($g->name)===mb_strtolower($data['name']))abort(422,'Tên đã tồn tại trong nhóm này.');
            $old=$item->exists?$item->toArray():null;$item->fill($data+['parent_id'=>null])->save();
            app(AuditLogger::class)->record('service_interest',$item->id,$id?'update_service_interest':'create_service_interest',$request->user(),$old,$item->toArray(),$request);
            return response()->json(['data'=>$item],$id?200:201);
        });
    }
}
