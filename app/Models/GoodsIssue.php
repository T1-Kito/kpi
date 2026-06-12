<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsIssue extends Model
{
    protected $fillable = ['tenant_id', 'code', 'sales_order_id', 'warehouse_id', 'warehouse_location_id', 'confirmed_by', 'confirmed_at', 'status'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsIssueItem::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
