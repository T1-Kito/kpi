<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sku extends Model
{
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
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
