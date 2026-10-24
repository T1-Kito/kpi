<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{User,Tenant};
use App\Support\AuditLogger;
use Illuminate\Http\{Request,UploadedFile};
use Illuminate\Support\Facades\{DB,Crypt,Schema};
use Illuminate\Validation\{Rule,ValidationException};

/** First rollout: durable, authorized batches over the existing create-only importers. */
class ImportBatchController extends Controller
{
    private const CATALOGS=['customers'=>'customer.manage','customers-v1'=>'customer.manage','contacts'=>'customer.manage','contacts-v1'=>'customer.manage','leads'=>'sales.lead.manage','leads-v1'=>'sales.lead.manage','categories-v1'=>'product.manage','brands-v1'=>'product.manage','skus-v1'=>'product.manage','suppliers-v1'=>'supplier.manage','warehouses-v1'=>'warehouse.manage','locations-v1'=>'warehouse.manage','positions-v1'=>'user.manage','departments-v1'=>'user.manage','users-v1'=>'user.manage'];
    private const MESSAGES=['required'=>':attribute không được để trống.','present'=>'Cần gửi :attribute.','prohibited'=>':attribute không được thay đổi.','string'=>':attribute phải là văn bản.','integer'=>':attribute phải là số nguyên.','array'=>':attribute phải là danh sách.','boolean'=>':attribute không hợp lệ.','in'=>':attribute không nằm trong danh sách được hỗ trợ.','exists'=>':attribute không hợp lệ hoặc không thuộc doanh nghiệp đang dùng.','regex'=>':attribute chỉ dùng chữ, số, dấu gạch ngang hoặc gạch dưới.','max'=>':attribute vượt giới hạn cho phép.','distinct'=>':attribute bị trùng.','file'=>'Vui lòng chọn file danh sách hợp lệ.','accepted'=>'Vui lòng xác nhận đã kiểm tra danh sách.','min'=>':attribute nhỏ hơn mức cho phép.'];
    private const ATTRIBUTES=['code'=>'Mã nguồn','name'=>'Tên nguồn','is_active'=>'Trạng thái nguồn','member_ids'=>'Danh sách người sử dụng','member_ids.*'=>'Người sử dụng','source_id'=>'Nguồn nhập','catalog'=>'Danh mục','file'=>'File danh sách','page'=>'Số trang','confirmed'=>'Xác nhận nhập'];

    private function available(): void {
        abort_unless(Schema::hasTable('import_batches') && Schema::hasTable('import_sources') && Schema::hasTable('import_source_users'),503,'Chức năng quản lý lô nhập chưa được nâng cấp dữ liệu. Chức năng nhập cũ vẫn dùng được.');
    }
    private function manager(User $user): bool { return $user->hasPermission('master.manage') && $user->hasPermission('role.manage') && $user->dataScope()==='company'; }
    private function source(User $actor,int $id,?string $catalog=null) {
        $source=DB::table('import_sources')->where('tenant_id',$actor->tenant_id)->where('id',$id)->first();
        abort_unless($source,404);
        if($catalog!==null) {
            abort_if($catalog==='skus-v1' && !$actor->hasPermission('master.cost.view'),403,'Nhập sản phẩm có giá vốn cần quyền xem giá vốn.');
            abort_unless($source->is_active,422,'Nguồn nhập đã ngừng hoạt động.');
            abort_unless(DB::table('import_source_users')->where('source_id',$id)->where('user_id',$actor->id)->exists(),403,'Bạn chưa được phép sử dụng nguồn nhập này.');
            abort_unless(isset(self::CATALOGS[$catalog]) && $actor->hasPermission(self::CATALOGS[$catalog]) && $actor->hasPermission('master.view') && $actor->dataScope()==='company',403,'Nhập theo nguồn cần quyền danh mục và phạm vi dữ liệu toàn công ty.');
        }
        return $source;
    }
    public function sources(Request $request) {
        $this->available();$actor=$request->user();$manager=$this->manager($actor);
        $query=DB::table('import_sources')->where('tenant_id',$actor->tenant_id);
        if(!$manager)$query->whereIn('id',DB::table('import_source_users')->where('user_id',$actor->id)->select('source_id'));
        $rows=$query->orderBy('name')->get();
        foreach($rows as $row) {
            $row->id=(int)$row->id;$row->is_active=(bool)$row->is_active;
            $row->can_use=DB::table('import_source_users')->where('source_id',$row->id)->where('user_id',$actor->id)->exists();
            $row->member_ids=$manager?DB::table('import_source_users')->where('source_id',$row->id)->pluck('user_id')->map(fn($id)=>(int)$id)->all():[];
        }
        $visibleCatalogs=Schema::hasTable('import_record_links')?self::CATALOGS:array_filter(self::CATALOGS,fn($catalog)=>!str_ends_with($catalog,'-v1'),ARRAY_FILTER_USE_KEY);
        if(!$actor->hasPermission('master.cost.view'))unset($visibleCatalogs['skus-v1']);
        return response()->json(['data'=>$rows,'meta'=>['can_manage'=>$manager,'catalogs'=>array_keys(array_filter($visibleCatalogs,fn($permission)=>$actor->hasPermission('master.view') && $actor->hasPermission($permission) && $actor->dataScope()==='company')),'users'=>$manager?User::where('tenant_id',$actor->tenant_id)->where('is_active',true)->get(['id','name']):[],'max_rows'=>300,'max_mb'=>1]]);
    }
    public function saveSource(Request $request,?int $source=null) {
        $this->available();$actor=$request->user();abort_unless($this->manager($actor),403,'Bạn chưa có quyền quản lý nguồn nhập.');
        if($source!==null)$this->source($actor,$source);
        $data=$request->validate(['code'=>[$source===null?'required':'prohibited','string','regex:/^[A-Za-z0-9_-]+$/','max:50'],'name'=>['required','string','max:150'],'is_active'=>['required','boolean'],'member_ids'=>['sometimes','array','max:200'],'member_ids.*'=>['integer','distinct',Rule::exists('users','id')->where('tenant_id',$actor->tenant_id)->where('is_active',true)]],self::MESSAGES,self::ATTRIBUTES);
        return DB::transaction(function()use($source,$data,$actor){
            Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
            if($source===null) {
                $code=mb_strtoupper($data['code']);
                abort_if(DB::table('import_sources')->where('tenant_id',$actor->tenant_id)->where('code',$code)->exists(),422,'Mã nguồn nhập đã tồn tại.');
                $id=DB::table('import_sources')->insertGetId(['tenant_id'=>$actor->tenant_id,'code'=>$code,'name'=>$data['name'],'is_active'=>$data['is_active'],'created_at'=>now(),'updated_at'=>now()]);
            }else{$id=$source;DB::table('import_sources')->where('id',$id)->where('tenant_id',$actor->tenant_id)->update(['name'=>$data['name'],'is_active'=>$data['is_active'],'updated_at'=>now()]);}
            if(array_key_exists('member_ids',$data)){
                DB::table('import_source_users')->where('source_id',$id)->delete();
                foreach($data['member_ids'] as $user)DB::table('import_source_users')->insert(['source_id'=>$id,'user_id'=>$user]);
            }
            app(AuditLogger::class)->record('import_source',$id,$source===null?'create_import_source':'update_import_source',$actor,null,['name'=>$data['name'],'is_active'=>$data['is_active'],'member_ids'=>$data['member_ids'] ?? 'unchanged']);
            return response()->json(['data'=>['id'=>$id]],$source===null?201:200);
        });
    }
    public function customerTemplate(Request $request) {
        $this->available();$actor=$request->user();
        abort_unless($actor->hasPermission('master.view') && $actor->hasPermission('customer.manage') && $actor->dataScope()==='company',403);
        $path=app(\App\Support\ImportTemplateOptions::class)->build(resource_path('templates/import/khach-hang-theo-nguon-v1.xlsx'),$actor,'customers');
        return response()->download($path,'Mẫu khách hàng.xlsx',['Cache-Control'=>'private, no-store, max-age=0'])->deleteFileAfterSend(true);
    }
    public function simpleCustomerPreview(Request $request) {
        return $this->simpleDirectoryPreview($request,'customers-v1');
    }
    public function simpleContactPreview(Request $request) {
        return $this->simpleDirectoryPreview($request,'contacts-v1');
    }
    public function simpleLeadPreview(Request $request) {
        return $this->simpleDirectoryPreview($request,'leads-v1');
    }
    public function productPreview(Request $request,string $kind) {
        abort_unless(in_array($kind,['categories','brands','skus'],true),404);
        return $this->simpleDirectoryPreview($request,$kind.'-v1');
    }
    public function procurementPreview(Request $request,string $kind){
        abort_unless(in_array($kind,['suppliers','warehouses','locations'],true),404);
        return $this->simpleDirectoryPreview($request,$kind.'-v1');
    }
    public function procurementTemplate(Request $request,string $kind){
        abort_unless(in_array($kind,['suppliers','warehouses','locations'],true),404);$this->available();$actor=$request->user();
        abort_unless($actor->hasPermission('master.view') && $actor->hasPermission(self::CATALOGS[$kind.'-v1']) && $actor->dataScope()==='company',403);
        $name=['suppliers'=>'nhà cung cấp','warehouses'=>'kho','locations'=>'vị trí kho'][$kind];$path=resource_path('templates/import/'.$kind.'-v1.xlsx');
        if($kind!=='suppliers')return response()->download(app(\App\Support\ImportTemplateOptions::class)->build($path,$actor,$kind),'Mẫu '.$name.'.xlsx',['Cache-Control'=>'private, no-store'])->deleteFileAfterSend(true);
        return response()->download($path,'Mẫu '.$name.'.xlsx',['Cache-Control'=>'private, no-store']);
    }
    public function organizationPreview(Request $request,string $kind){
        abort_unless(in_array($kind,['positions','departments','users'],true),404);
        return $this->simpleDirectoryPreview($request,$kind.'-v1');
    }
    public function organizationTemplate(Request $request,string $kind){
        abort_unless(in_array($kind,['positions','departments','users'],true),404);$this->available();$actor=$request->user();
        abort_unless($actor->hasPermission('master.view') && $actor->hasPermission('user.manage') && $actor->dataScope()==='company',403);
        $name=['positions'=>'chức vụ','departments'=>'phòng ban','users'=>'người dùng'][$kind];$path=resource_path('templates/import/'.$kind.'-v1.xlsx');
        if($kind!=='positions')return response()->download(app(\App\Support\ImportTemplateOptions::class)->build($path,$actor,$kind),'Mẫu '.$name.'.xlsx',['Cache-Control'=>'private, no-store'])->deleteFileAfterSend(true);
        return response()->download($path,'Mẫu '.$name.'.xlsx',['Cache-Control'=>'private, no-store']);
    }
    public function productTemplate(Request $request,string $kind) {
        abort_unless(in_array($kind,['categories','brands','skus'],true),404);$this->available();$actor=$request->user();
        abort_unless($actor->hasPermission('master.view') && $actor->hasPermission('product.manage') && $actor->dataScope()==='company',403);
        abort_if($kind==='skus' && !$actor->hasPermission('master.cost.view'),403,'Nhập sản phẩm có giá vốn cần quyền xem giá vốn.');
        $name=['categories'=>'Danh mục sản phẩm','brands'=>'Hãng sản phẩm','skus'=>'Sản phẩm và mã hàng'][$kind];
        $template=resource_path('templates/import/'.$kind.'-v1.xlsx');
        if($kind==='skus')return response()->download(app(\App\Support\ImportTemplateOptions::class)->build($template,$actor,'skus'),'Mẫu '.$name.'.xlsx',['Cache-Control'=>'private, no-store'])->deleteFileAfterSend(true);
        return response()->download($template,'Mẫu '.$name.'.xlsx',['Cache-Control'=>'private, no-store']);
    }
    private function simpleDirectoryPreview(Request $request,string $catalog) {
        $this->available();$actor=$request->user();
        abort_unless($actor->hasPermission('master.view') && $actor->hasPermission(self::CATALOGS[$catalog]) && $actor->dataScope()==='company',403);
        return DB::transaction(function()use($request,$actor,$catalog){
            Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
            $source=DB::table('import_sources')->where('tenant_id',$actor->tenant_id)->where('code','CRM-CUSTOMER-DEFAULT')->first();
            if(!$source){$id=DB::table('import_sources')->insertGetId(['tenant_id'=>$actor->tenant_id,'code'=>'CRM-CUSTOMER-DEFAULT','name'=>'Nhập khách hàng','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);}
            else{abort_unless($source->is_active,422,'Nhập khách hàng đang tạm ngừng. Liên hệ quản trị.');$id=$source->id;}
            if(!DB::table('import_source_users')->where('source_id',$id)->where('user_id',$actor->id)->exists())DB::table('import_source_users')->insert(['source_id'=>$id,'user_id'=>$actor->id]);
            $request->merge(['source_id'=>(int)$id,'catalog'=>$catalog]);
            return $this->preview($request);
        });
    }
    public function contactTemplate(Request $request) {
        $this->available();$actor=$request->user();
        abort_unless($actor->hasPermission('master.view') && $actor->hasPermission('customer.manage') && $actor->dataScope()==='company',403);
        $path=app(\App\Support\ImportTemplateOptions::class)->build(resource_path('templates/import/nguoi-lien-he-theo-nguon-v1.xlsx'),$actor,'contacts');
        return response()->download($path,'Mẫu người liên hệ.xlsx',['Cache-Control'=>'private, no-store, max-age=0'])->deleteFileAfterSend(true);
    }
    public function leadTemplate(Request $request) {
        $this->available();$actor=$request->user();
        abort_unless($actor->hasPermission('master.view') && $actor->hasPermission('sales.lead.manage') && $actor->dataScope()==='company',403);
        $path=app(\App\Support\ImportTemplateOptions::class)->build(resource_path('templates/import/khach-tiem-nang-theo-nguon-v1.xlsx'),$actor,'leads');
        return response()->download($path,'Mẫu khách hàng tiềm năng.xlsx',['Cache-Control'=>'private, no-store, max-age=0'])->deleteFileAfterSend(true);
    }
    public function index(Request $request) {
        $this->available();$request->validate(['page'=>['sometimes','integer','min:1']],self::MESSAGES,self::ATTRIBUTES);$actor=$request->user();
        $rows=DB::table('import_batches')->where('tenant_id',$actor->tenant_id)->where('user_id',$actor->id)->select('id','source_id','catalog','original_name','status','expires_at','committed_at','created_at')->orderByDesc('id')->paginate(20);
        foreach($rows as $row)if($row->status==='ready' && \Carbon\Carbon::parse($row->expires_at)->isPast())$row->status='expired';
        return response()->json(['data'=>$rows->items(),'meta'=>['page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }
    private function batch(User $actor,int $id) {
        $batch=DB::table('import_batches')->where('tenant_id',$actor->tenant_id)->where('user_id',$actor->id)->where('id',$id)->first();abort_unless($batch,404);return $batch;
    }
    public function show(Request $request,int $batch) {
        $this->available();$row=$this->batch($request->user(),$batch);$this->source($request->user(),(int)$row->source_id,$row->catalog);
        return response()->json(['data'=>$this->publicBatch($row)]);
    }
    public function errors(Request $request,int $batch) {
        $this->available();$actor=$request->user();$row=$this->batch($actor,$batch);
        $this->source($actor,(int)$row->source_id,$row->catalog);
        $result=json_decode($row->result_json ?? '{}',true);
        abort_unless(!empty($result['errors']),422,'Lô nhập không còn chi tiết lỗi để tải.');
        $rows=[['LỖI KIỂM TRA DANH SÁCH'],['Lô nhập',(int)$row->id],['File',$row->original_name],['Sửa trong file gốc rồi kiểm tra lại toàn bộ danh sách. File này chỉ dùng để đối chiếu lỗi.'],['Dòng trong file gốc','Nội dung cần sửa']];
        foreach($result['errors'] as $error)$rows[]=[(int)$error['row'],(string)$error['message']];
        $path=app(\App\Services\Kpi\KpiReportWorkbook::class)->writeSheets([['Danh sách lỗi',$rows,5]]);
        app(AuditLogger::class)->record('import_batch',$batch,'download_import_errors',$actor,null,['error_count'=>count($result['errors'])]);
        return response()->download($path,'loi-nhap-danh-sach-'.$batch.'.xlsx',['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff'])->deleteFileAfterSend(true);
    }
    private function publicBatch($row): array {return ['id'=>(int)$row->id,'source_id'=>(int)$row->source_id,'catalog'=>$row->catalog,'status'=>$row->status==='ready' && \Carbon\Carbon::parse($row->expires_at)->isPast()?'expired':$row->status,'original_name'=>$row->original_name,'expires_at'=>\Carbon\Carbon::parse($row->expires_at)->toIso8601String(),'committed_at'=>$row->committed_at,'result'=>json_decode($row->result_json ?? '{}',true)];}
    private function importer(Request $parent,string $catalog,string $bytes,string $extension,bool $confirm,?int $sourceId=null):array {
        $path=tempnam(sys_get_temp_dir(),'vkimport-');abort_unless($path!==false,503,'Không tạo được vùng kiểm tra file.');
        try {
            if(file_put_contents($path,$bytes)!==strlen($bytes))abort(503,'Không chuẩn bị được file nhập.');
            if(in_array($catalog,['positions-v1','departments-v1','users-v1'],true))return app(\App\Support\OrganizationSourceImport::class)->run($parent,$path,(int)$sourceId,$confirm,substr($catalog,0,-3));
            if(in_array($catalog,['suppliers-v1','warehouses-v1','locations-v1'],true))return app(\App\Support\ProcurementSourceImport::class)->run($parent,$path,(int)$sourceId,$confirm,substr($catalog,0,-3));
            if(in_array($catalog,['categories-v1','brands-v1','skus-v1'],true))return app(\App\Support\ProductSourceImport::class)->run($parent,$path,(int)$sourceId,$confirm,substr($catalog,0,-3));
            if($catalog==='customers-v1')return app(\App\Support\CustomerSourceImport::class)->run($parent,$path,(int)$sourceId,$confirm);
            if($catalog==='contacts-v1')return app(\App\Support\ContactSourceImport::class)->run($parent,$path,(int)$sourceId,$confirm);
            if($catalog==='leads-v1')return app(\App\Support\LeadSourceImport::class)->run($parent,$path,(int)$sourceId,$confirm);
            $server=$parent->server->all();$server['CONTENT_TYPE']='multipart/form-data';unset($server['CONTENT_LENGTH'],$server['HTTP_CONTENT_TYPE']);
            $child=Request::create('/internal/import','POST',['confirm'=>$confirm?'1':'0'],[],['file'=>new UploadedFile($path,'danh-sach.'.$extension,null,null,true)],$server);$child->setUserResolver(fn()=>$parent->user());
            $response=match($catalog){
                'customers'=>app(CustomerImportController::class)->store($child,app(CustomerController::class)),
                default=>app(DirectoryImportController::class)->store($child,$catalog,app(CustomerController::class),app(LeadController::class)),
            };
            return $response->getData(true)['data'];
        }finally{if(is_file($path))unlink($path);}
    }
    public function preview(Request $request) {
        $this->available();$data=$request->validate(['source_id'=>['required','integer'],'catalog'=>['required',Rule::in(array_keys(self::CATALOGS))],'file'=>['required','file','max:1024']],self::MESSAGES,self::ATTRIBUTES);
        $actor=$request->user();$this->source($actor,(int)$data['source_id'],$data['catalog']);$file=$request->file('file');
        $extension=strtolower($file->getClientOriginalExtension());abort_unless($extension==='xlsx' || ($data['catalog']==='customers' && $extension==='csv'),422,'Chọn file Excel .xlsx theo mẫu; khách hàng có thể dùng CSV.');
        $bytes=file_get_contents($file->getRealPath());$hash=hash('sha256',$bytes);
        return DB::transaction(function()use($request,$data,$actor,$bytes,$hash,$extension,$file){
            Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();$this->source($actor,(int)$data['source_id'],$data['catalog']);
            $old=DB::table('import_batches')->where('tenant_id',$actor->tenant_id)->where('source_id',$data['source_id'])->where('catalog',$data['catalog'])->where('file_hash',$hash)->where('status','committed')->first();
            if($old){abort_unless((int)$old->user_id===$actor->id,409,'File này đã được nhập từ nguồn đã chọn. Không nhập thêm.');return response()->json(['data'=>$this->publicBatch($old),'replayed'=>true]);}
            $result=$this->importer($request,$data['catalog'],$bytes,$extension,false,(int)$data['source_id']);
            $id=DB::table('import_batches')->insertGetId(['tenant_id'=>$actor->tenant_id,'source_id'=>$data['source_id'],'user_id'=>$actor->id,'catalog'=>$data['catalog'],'file_hash'=>$hash,'original_name'=>mb_substr(basename($file->getClientOriginalName()),0,200),'extension'=>$extension,'status'=>$result['can_import']?'ready':'invalid','result_json'=>json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),'encrypted_payload'=>$result['can_import']?Crypt::encryptString(base64_encode($bytes)):null,'expires_at'=>now()->addHours(2),'created_at'=>now(),'updated_at'=>now()]);
            app(AuditLogger::class)->record('import_batch',$id,'preview_import_batch',$actor,null,['catalog'=>$data['catalog'],'source_id'=>$data['source_id'],'total'=>$result['total'],'can_import'=>$result['can_import']]);
            return response()->json(['data'=>$this->publicBatch(DB::table('import_batches')->where('id',$id)->first())],201);
        });
    }
    public function commit(Request $request,int $batch) {
        $this->available();$request->validate(['confirmed'=>['required','accepted']],self::MESSAGES,self::ATTRIBUTES);$actor=$request->user();$this->batch($actor,$batch);
        return DB::transaction(function()use($request,$actor,$batch){
            Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();$row=$this->batch($actor,$batch);$this->source($actor,(int)$row->source_id,$row->catalog);
            if($row->status==='committed')return response()->json(['data'=>$this->publicBatch($row),'replayed'=>true]);
            abort_unless($row->status==='ready' && $row->encrypted_payload && \Carbon\Carbon::parse($row->expires_at)->isFuture(),422,'Lô nhập chưa hợp lệ hoặc đã hết hạn. Hãy tải file để kiểm tra lại.');
            $other=DB::table('import_batches')->where('tenant_id',$actor->tenant_id)->where('source_id',$row->source_id)->where('catalog',$row->catalog)->where('file_hash',$row->file_hash)->where('status','committed')->exists();
            abort_if($other,409,'File này đã được nhập. Không tạo thêm bản ghi.');
            $bytes=base64_decode(Crypt::decryptString($row->encrypted_payload),true);abort_unless($bytes!==false && hash_equals($row->file_hash,hash('sha256',$bytes)),422,'File kiểm tra đã thay đổi. Hãy kiểm tra lại từ đầu.');
            $fresh=$this->importer($request,$row->catalog,$bytes,$row->extension,false,(int)$row->source_id);
            $approved=json_decode($row->result_json ?? '{}',true);
            if(!$fresh['can_import'] || ($fresh['total'] ?? null)!==($approved['total'] ?? null) || ($fresh['preview'] ?? [])!==($approved['preview'] ?? [])) {
                throw ValidationException::withMessages(['file'=>['Dữ liệu hoặc kết quả kiểm tra đã thay đổi. Hãy tải file và kiểm tra lại trước khi xác nhận.']]);
            }
            $result=$this->importer($request,$row->catalog,$bytes,$row->extension,true,(int)$row->source_id);
            if(!$result['can_import'] || !($result['imported']+($result['skipped'] ?? 0)))throw ValidationException::withMessages(['file'=>array_column($result['errors'] ?? [],'message') ?: ['Dữ liệu đã thay đổi sau bước kiểm tra. Chưa ghi lô nhập.']]);
            DB::table('import_batches')->where('id',$batch)->update(['status'=>'committed','result_json'=>json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),'encrypted_payload'=>null,'committed_at'=>now(),'updated_at'=>now()]);
            app(AuditLogger::class)->record('import_batch',$batch,'commit_import_batch',$actor,null,['catalog'=>$row->catalog,'source_id'=>$row->source_id,'imported'=>$result['imported']]);
            return response()->json(['data'=>$this->publicBatch(DB::table('import_batches')->where('id',$batch)->first())]);
        });
    }
}
