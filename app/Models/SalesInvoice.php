<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'tax_invoice_symbol',
        'tax_invoice_no',
        'sales_order_id',
        'invoice_date',
        'issued_at',
        'due_date',
        'subtotal_amount',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'balance_amount',
        'issued_by',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'issued_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
