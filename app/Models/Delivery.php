<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'sales_order_id',
        'goods_issue_id',
        'recipient_name',
        'recipient_phone',
        'delivery_address',
        'delivered_at',
        'proof_note',
        'confirmed_by',
        'status',
    ];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function goodsIssue(): BelongsTo
    {
        return $this->belongsTo(GoodsIssue::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
