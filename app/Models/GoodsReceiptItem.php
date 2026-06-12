<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptItem extends Model
{
    protected $fillable = ['goods_receipt_id', 'sku_id', 'quantity', 'lot_no'];

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
