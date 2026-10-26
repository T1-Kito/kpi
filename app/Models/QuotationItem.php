<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $fillable = ['quotation_id', 'sku_id', 'sku_code', 'list_unit_price', 'name', 'unit', 'quantity', 'unit_price', 'unit_cost', 'line_subtotal', 'vat_rate', 'vat_amount', 'line_total', 'line_cost'];

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class)->withoutGlobalScope('catalog_visibility');
    }
}
