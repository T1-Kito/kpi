<?php
namespace App\Support;
use App\Models\ProductCategory;
use Illuminate\Validation\ValidationException;
final class ProductCategoryTree
{
    public static function syncService(ProductCategory $item): void
    {
        $parent=$item->parent_id?ProductCategory::where('tenant_id',$item->tenant_id)->find($item->parent_id):null;
        if($parent && !$parent->service_interest_id)self::syncService($parent);
        $parentId=$parent?->service_interest_id;
        $service=$item->service_interest_id?\App\Models\ServiceInterest::where('tenant_id',$item->tenant_id)->find($item->service_interest_id):null;
        $service ??= \App\Models\ServiceInterest::where('tenant_id',$item->tenant_id)->where('name',$item->name)->where('parent_id',$parentId)->first();
        $service ??= new \App\Models\ServiceInterest(['tenant_id'=>$item->tenant_id]);
        $service->fill(['name'=>$item->name,'parent_id'=>$parentId,'is_active'=>$item->status==='active']);$service->save();
        if((int)$item->service_interest_id!==(int)$service->id){$item->service_interest_id=$service->id;$item->saveQuietly();}
    }
    public static function validate(int $tenant, ?int $parent, ?ProductCategory $item, string $status): void
    {
        if($parent){
            $node=ProductCategory::where('tenant_id',$tenant)->find($parent);
            if(!$node || $node->parent_id || $node->id===$item?->id)throw ValidationException::withMessages(['parent_id'=>'Nhóm cha không hợp lệ. Danh mục chỉ có tối đa 2 cấp.']);
            if($status==='active' && $node->status!=='active')throw ValidationException::withMessages(['parent_id'=>'Nhóm cha đã ngừng sử dụng.']);
            if($item && ProductCategory::where('parent_id',$item->id)->exists())throw ValidationException::withMessages(['parent_id'=>'Nhóm đang có nhóm con, không thể chuyển xuống cấp 2.']);
        }
        if($item && $status==='inactive' && ProductCategory::where('parent_id',$item->id)->where('status','active')->exists())throw ValidationException::withMessages(['status'=>'Hãy ngừng sử dụng các nhóm con trước khi ngừng nhóm cha.']);
    }
    public static function path(ProductCategory $item): string
    {
        $parent=$item->parent_id?ProductCategory::where('tenant_id',$item->tenant_id)->find($item->parent_id):null;
        return $parent?$parent->name.' / '.$item->name:$item->name;
    }
    public static function active(ProductCategory $item): bool
    {
        return $item->status==='active' && (!$item->parent_id || ProductCategory::where('tenant_id',$item->tenant_id)->whereKey($item->parent_id)->whereNull('parent_id')->where('status','active')->exists());
    }
}
