<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductCategory extends Model {
    protected static function booted():void {
        static::saved(fn($item)=>\App\Support\ProductCategoryTree::syncService($item));
        static::deleted(function($item){if($item->service_interest_id)\App\Models\ServiceInterest::where('tenant_id',$item->tenant_id)->whereKey($item->service_interest_id)->update(['is_active'=>false]);});
    }
    use \App\Models\Concerns\CastsDatabaseIdentifiers;
 protected $fillable = ['tenant_id', 'parent_id', 'name', 'status']; }
