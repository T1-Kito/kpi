<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPayment extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'sales_invoice_id',
        'sales_order_id',
        'amount',
        'paid_at',
        'payment_method',
        'reference_no',
        'note',
        'received_by',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
