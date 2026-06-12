<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    protected $fillable = ['tenant_id', 'code', 'quotation_id', 'customer_id', 'sales_owner_id', 'subtotal_amount', 'tax_amount', 'total_amount', 'stock_status', 'status'];

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
