<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    protected $fillable = ['purchase_request_id', 'sku_id', 'quantity', 'available_qty'];

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
