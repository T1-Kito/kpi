<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    protected $fillable = [
        'tenant_id', 'code', 'customer_id', 'lead_id', 'deal_id', 'price_book_id', 'payment_term_id', 'duplicated_from_id', 'sales_owner_id',
        'subtotal_amount', 'tax_amount', 'total_amount', 'total_cost', 'margin_percent',
        'valid_until', 'payment_terms', 'delivery_terms', 'note', 'status',
        'workflow_version', 'created_by', 'contact_id', 'revision_root_id', 'revision_number',
        'discount_percent', 'discount_amount', 'document_snapshot', 'approval_steps',
        'approval_policy_snapshot', 'submitted_at', 'issued_at', 'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'duplicated_from_id' => 'integer',
            'valid_until' => 'date',
            'document_snapshot' => 'array', 'approval_steps' => 'array', 'approval_policy_snapshot' => 'array',
            'submitted_at' => 'datetime', 'issued_at' => 'datetime', 'accepted_at' => 'datetime',
            'discount_percent' => 'decimal:2', 'discount_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }

    public function duplicatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicated_from_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(SalesPriceBook::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(SalesPaymentTerm::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }
}
