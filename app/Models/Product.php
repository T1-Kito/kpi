<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = ['tenant_id', 'parent_id', 'category_id', 'brand_id', 'code', 'name', 'image_path', 'description', 'technical_specs', 'status'];
    protected function casts(): array { return ['technical_specs' => 'array']; }
    public function category(): BelongsTo { return $this->belongsTo(ProductCategory::class); }
    public function brand(): BelongsTo { return $this->belongsTo(ProductBrand::class); }
}
