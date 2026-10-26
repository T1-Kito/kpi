<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Customer,Deal,Tenant};
use App\Support\{CatalogAccess,CrmIntakeImport,CrmIntakeWorkbook,DataScope};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt,DB,Schema};

final class CrmIntakeController extends Controller
{
    private function authorizeImport(Request $request):void
    {
        abort_unless($request->user()->hasPermission('customer.manage') && $request->user()->hasPermission('master.view') && $request->user()->hasPermission('sales.deal.view'),403,'Cần quyền quản lý khách hàng, xem danh mục và cơ hội để nhập tổng hợp.');
        abort_unless(Schema::hasTable('crm_intake_rows'),503,'Cần nâng cấp dữ liệu trước khi nhập tổng hợp.');
    }

    public function preview(Request $request,CrmIntakeWorkbook $reader,CrmIntakeImport $import)
    {
        $this->authorizeImport($request);
        $request->validate(['file'=>['required','file','max:1024'],'source_name'=>['required','string','max:150']],['required'=>'Vui lòng nhập :attribute.','file'=>'Vui lòng chọn file Excel.','max'=>':attribute vượt giới hạn cho phép.'],['file'=>'File Excel (tối đa 1 MB)','source_name'=>'tên nguồn danh sách']);
        abort_unless(strtolower($request->file('file')->getClientOriginalExtension())==='xlsx',422,'Chọn file Excel .xlsx.');
        $source=trim($request->input('source_name'));abort_if($source==='',422,'Điền tên nguồn danh sách. Dùng lại cùng tên khi nhập lại danh sách từ nguồn này.');
        $read=$reader->read($request->file('file')->getRealPath());
        return DB::transaction(function()use($request,$import,$read,$source){
            Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $plan=$import->plan($request->user(),$read['rows'],$source);
            $payload=json_encode(['kind'=>'crm_intake','actor'=>$request->user()->id,'tenant'=>$request->user()->tenant_id,'expires'=>time()+900,'snapshot'=>$import->snapshot($request->user()),'source'=>$source,'rows'=>$read['rows']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            abort_if(strlen($payload)>500000,422,'Nội dung danh sách quá dài. Chia thành file nhỏ hơn để kiểm tra.');
            $public=array_map(fn($row)=>array_filter($row,fn($key)=>!str_starts_with($key,'_'),ARRAY_FILTER_USE_KEY),$plan['rows']);
            return response()->json(['data'=>['rows'=>$public,'labels'=>CrmIntakeWorkbook::LABELS,'ignored'=>$read['ignored'],'error_count'=>$plan['errors'],'can_commit'=>$plan['errors']===0,'token'=>$plan['errors']===0?Crypt::encryptString($payload):null]]);
        });
    }

    public function commit(Request $request,CrmIntakeImport $import)
    {
        $this->authorizeImport($request);
        $request->validate(['token'=>['required','string','max:1000000'],'confirmed'=>['required','accepted']],['required'=>'Vui lòng xác nhận bản xem trước.','accepted'=>'Vui lòng xác nhận đã kiểm tra từng dòng.','max'=>'Bản xem trước vượt giới hạn.']);
        try{$p=json_decode(Crypt::decryptString($request->input('token')),true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){abort(422,'Bản xem trước không hợp lệ. Vui lòng kiểm tra lại file.');}
        abort_unless(($p['kind'] ?? '')==='crm_intake',422,'Bản xem trước không đúng loại nhập.');
        abort_unless((int)$p['actor']===(int)$request->user()->id && (int)$p['tenant']===(int)$request->user()->tenant_id,403);
        abort_if($p['expires']<time(),422,'Bản xem trước đã hết hạn. Vui lòng kiểm tra lại file.');
        return DB::transaction(function()use($request,$import,$p){
            Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($p['snapshot'],$import->snapshot($request->user())),409,'Dữ liệu đã thay đổi từ lúc xem trước. Vui lòng kiểm tra lại file để tránh ghép nhầm hoặc tạo trùng.');
            $plan=$import->plan($request->user(),$p['rows'],$p['source']);
            abort_unless($plan['errors']===0,422,'Danh sách không còn hợp lệ. Vui lòng kiểm tra lại file.');
            return response()->json(['data'=>$import->commit($request->user(),$plan)]);
        });
    }

    public function history(Request $request)
    {
        $this->authorizeImport($request);
        $query=DB::table('crm_intake_rows')->where('tenant_id',$request->user()->tenant_id);
        if(!CatalogAccess::full($request->user()))$query->where('user_id',$request->user()->id);
        $query->whereIn('customer_id',CatalogAccess::visible(Customer::withoutGlobalScope('catalog_visibility'),$request->user())->select('customers.id'));
        $query->where(fn($q)=>$q->whereNull('deal_id')->orWhereIn('deal_id',DataScope::owned(Deal::where('tenant_id',$request->user()->tenant_id),$request->user(),'owner_id','owner')->select('deals.id')));
        $page=$query->orderByDesc('id')->paginate(20);
        $rows=$page->getCollection()->map(function($r){return ['id'=>(int)$r->id,'source'=>$r->source_name,'customer_id'=>(int)$r->customer_id,'deal_id'=>$r->deal_id?(int)$r->deal_id:null,'created_at'=>$r->created_at,'values'=>json_decode(Crypt::decryptString($r->encrypted_payload),true,512,JSON_THROW_ON_ERROR)];});
        return response()->json(['data'=>$rows,'meta'=>['page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'total'=>$page->total(),'labels'=>CrmIntakeWorkbook::LABELS]]);
    }
}
