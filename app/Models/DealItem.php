<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealItem extends Model
{
    protected $fillable = ['deal_id', 'sku_id', 'quantity', 'unit_price', 'line_total'];
    protected function casts(): array { return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2']; }
    public function sku(): BelongsTo { return $this->belongsTo(Sku::class); }
}
