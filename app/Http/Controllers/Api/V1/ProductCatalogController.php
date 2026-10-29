<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductCatalogController extends Controller
{
    public function importTree(Request $request): JsonResponse {
        $tenant=$request->user()->tenant_id;
        $groups=fn()=>ProductCategory::where('tenant_id',$tenant)->get()->map(fn($g)=>(object)['id'=>$g->id,'parent_id'=>$g->parent_id,'name'=>$g->name,'is_active'=>$g->status==='active','updated_at'=>$g->updated_at]);
        $planner=new \App\Support\CustomerGroupImport;
        if(!$request->filled('token')){
            $request->validate(['file'=>'required|file|mimes:xlsx|max:1024']);
            $rows=app(\App\Support\CustomerGroupWorkbook::class)->read($request->file('file')->getRealPath(),true);
            $plan=$planner->plan($rows,$groups());$token=bin2hex(random_bytes(24));
            \Illuminate\Support\Facades\Cache::put('category-tree:'.$request->user()->id.':'.$token,['rows'=>$rows,'snapshot'=>$planner->snapshot($groups())],now()->addMinutes(15));
            return response()->json(['data'=>['token'=>$token,'rows'=>$plan['rows'],'create_count'=>count($plan['new'])]]);
        }
        $key='category-tree:'.$request->user()->id.':'.$request->input('token');
        $payload=\Illuminate\Support\Facades\Cache::get($key);abort_unless($payload,422,'Lần kiểm tra đã hết hạn. Vui lòng chọn file và kiểm tra lại.');
        return \Illuminate\Support\Facades\DB::transaction(function()use($request,$tenant,$groups,$planner,$payload,$key){
            \App\Models\Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($payload['snapshot'],$planner->snapshot($groups())),409,'Danh mục đã thay đổi. Vui lòng kiểm tra file lại.');
            $plan=$planner->plan($payload['rows'],$groups());$ids=[];
            foreach($plan['new'] as $node){
                $parent=str_starts_with($node['parent'],'new:')?$ids[$node['parent']]:($node['parent']?:null);
                $child=Request::create('/internal','POST',['name'=>$node['name'],'parent_id'=>$parent,'status'=>'active']);$child->setUserResolver(fn()=>$request->user());
                $response=$this->storeCategory($child);abort_unless($response->getStatusCode()===201,422,'Tên nhóm đã tồn tại ở nhánh khác. Kiểm tra trước khi nhập.');$ids[$node['ref']]=$response->getData(true)['data']['id'];
            }
            \Illuminate\Support\Facades\Cache::forget($key);
            return response()->json(['data'=>['created'=>count($ids)]]);
        });
    }
    public function categories(Request $request): JsonResponse {
        $rows=ProductCategory::where('tenant_id',$request->user()->tenant_id)->orderBy('name')->get(['id','parent_id','name','status']);$byId=$rows->keyBy('id');
        return response()->json(['data'=>$rows->map(fn($item)=>$item->toArray()+['path'=>$item->parent_id?($byId[$item->parent_id]->name.' / '.$item->name):$item->name])]);
    }
    public function brands(Request $request): JsonResponse { return response()->json(['data' => ProductBrand::where('tenant_id', $request->user()->tenant_id)->orderBy('name')->get(['id','name','status'])]); }
    public function storeCategory(Request $request): JsonResponse { return $this->store($request, ProductCategory::class); }
    public function storeBrand(Request $request): JsonResponse { return $this->store($request, ProductBrand::class); }
    public function updateCategory(Request $request, int $id): JsonResponse { return $this->updateItem($request, ProductCategory::class, $id); }
    public function updateBrand(Request $request, int $id): JsonResponse { return $this->updateItem($request, ProductBrand::class, $id); }
    public function deleteCategory(Request $request, int $id): JsonResponse { return $this->deleteItem($request, ProductCategory::class, $id, 'category_id'); }
    public function deleteBrand(Request $request, int $id): JsonResponse { return $this->deleteItem($request, ProductBrand::class, $id, 'brand_id'); }
    private function updateItem(Request $request, string $model, int $id): JsonResponse {
        $item=$model::where('tenant_id',$request->user()->tenant_id)->findOrFail($id);
        $request->merge(['name'=>trim((string)$request->input('name'))]);
        $data=$request->validate(['name'=>['required','string','max:100',Rule::unique($item->getTable(),'name')->where('tenant_id',$item->tenant_id)->ignore($id)],'status'=>['required',Rule::in(['active','inactive'])]]);
        if($model===ProductCategory::class){
            $request->validate(['parent_id'=>'nullable|integer']);
            $data['parent_id']=$request->has('parent_id')?($request->input('parent_id')?:null):$item->parent_id;
            \App\Support\ProductCategoryTree::validate($item->tenant_id,$data['parent_id'],$item,$data['status']);
        }
        $item->update($data);
        return response()->json(['data'=>$item->fresh()]);
    }
    private function deleteItem(Request $request, string $model, int $id, string $column): JsonResponse {
        return \Illuminate\Support\Facades\DB::transaction(function()use($request,$model,$id,$column){
            $item=$model::where('tenant_id',$request->user()->tenant_id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if($model===ProductCategory::class)abort_if(ProductCategory::where('parent_id',$id)->exists(),422,'Nhóm đang có nhóm con. Hãy xử lý nhóm con trước.');
            abort_if(\App\Models\Product::withoutGlobalScopes()->where($column,$id)->exists(),422,'Không thể xóa vì đã có sản phẩm sử dụng. Bạn có thể đổi danh mục/hãng trên sản phẩm hoặc chuyển sang Ngừng sử dụng.');
            $item->delete();
            return response()->json(['message'=>'Đã xóa.']);
        });
    }
    private function store(Request $request, string $model): JsonResponse {
        $data = $request->validate(['name' => ['required','string','max:100'], 'status' => ['nullable', Rule::in(['active','inactive'])]]);
        $extra=[];
        if($model===ProductCategory::class){
            $request->validate(['parent_id'=>'nullable|integer']);$extra['parent_id']=$request->input('parent_id')?:null;
            \App\Support\ProductCategoryTree::validate($request->user()->tenant_id,$extra['parent_id'],null,$data['status']??'active');
            $existing=$model::where('tenant_id',$request->user()->tenant_id)->where('name',trim($data['name']))->first();
            abort_if($existing && (int)$existing->parent_id!==(int)$extra['parent_id'],422,'Tên nhóm đã tồn tại ở nhánh khác. Hãy chọn tên phân biệt.');
        }
        $item = $model::firstOrCreate(['tenant_id' => $request->user()->tenant_id, 'name' => trim($data['name'])], ['status' => $data['status'] ?? 'active']+$extra);
        return response()->json(['data' => $item], $item->wasRecentlyCreated ? 201 : 200);
    }
}
