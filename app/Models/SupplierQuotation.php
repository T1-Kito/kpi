<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierQuotation extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'purchase_request_id',
        'supplier_id',
        'created_by',
        'quoted_at',
        'valid_until',
        'total_amount',
        'status',
        'note',
        'selected_by',
        'selected_at',
    ];

    protected function casts(): array
    {
        return [
            'quoted_at' => 'date',
            'valid_until' => 'date',
            'selected_at' => 'datetime',
            'total_amount' => 'decimal:2',
        ];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function selector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierQuotationLine::class);
    }
}
