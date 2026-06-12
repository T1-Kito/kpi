<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    protected $fillable = ['tenant_id', 'code', 'customer_id', 'lead_id', 'sales_owner_id', 'subtotal_amount', 'tax_amount', 'total_amount', 'total_cost', 'margin_percent', 'status'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }
}
