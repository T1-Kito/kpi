<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'tenant_id', 'code', 'purchase_request_id', 'supplier_quotation_id', 'supplier_id', 'created_by',
        'expected_delivery_date', 'payment_terms', 'delivery_terms', 'warranty_terms', 'shipping_fee',
        'total_amount', 'status',
    ];

    protected function casts(): array
    {
        return [
            'expected_delivery_date' => 'date',
            'shipping_fee' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function supplierQuotation(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotation::class);
    }
}
