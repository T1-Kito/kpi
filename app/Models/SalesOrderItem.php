<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    protected $fillable = ['sales_order_id', 'sku_id', 'quantity', 'unit_price', 'line_subtotal', 'vat_rate', 'vat_amount', 'line_total'];

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
