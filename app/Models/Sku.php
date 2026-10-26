<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sku extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;
    use \App\Models\Concerns\HasCatalogSharing;

    protected $casts = ['is_shared' => 'boolean'];
    protected $attributes = ['is_shared' => false];
    protected $appends = ['can_manage_sharing'];

    protected $fillable = [
        'tenant_id',
        'product_id',
        'sku_code',
        'name',
        'barcode',
        'serial_number',
        'unit',
        'min_stock',
        'max_stock',
        'sale_price',
        'cost_price',
        'status',
        'created_by',
        'is_shared',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
