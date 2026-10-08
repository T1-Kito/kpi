<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'quotation_id',
        'customer_id',
        'sales_owner_id',
        'subtotal_amount',
        'tax_amount',
        'total_amount',
        'payment_terms',
        'fulfillment_type',
        'supplier_delivery_note',
        'stock_status',
        'delivery_status',
        'payment_status',
        'delivered_at',
        'completed_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function salesOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class);
    }
}
