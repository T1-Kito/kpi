<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

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
        'acceptance_status',
        'accepted_at',
        'accepted_by_name',
        'accepted_by_title',
        'quantity_accepted',
        'condition_accepted',
        'documents_accepted',
        'acceptance_note',
        'acceptance_file_path',
        'acceptance_file_name',
        'discrepancy_note',
        'acceptance_confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime', 'accepted_at' => 'datetime',
            'quantity_accepted' => 'boolean', 'condition_accepted' => 'boolean', 'documents_accepted' => 'boolean',
        ];
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

    public function acceptanceConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acceptance_confirmed_by');
    }
}
