<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $fillable = ['purchase_order_id', 'sku_id', 'quantity', 'unit_price', 'line_total'];

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
