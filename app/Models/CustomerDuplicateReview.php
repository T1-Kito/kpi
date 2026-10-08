<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDuplicateReview extends Model
{
    protected $fillable = [
        'tenant_id', 'first_customer_id', 'second_customer_id', 'matched_on', 'status',
        'retained_customer_id', 'reviewed_by', 'reviewed_at', 'reason',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function firstCustomer(): BelongsTo { return $this->belongsTo(Customer::class, 'first_customer_id'); }
    public function secondCustomer(): BelongsTo { return $this->belongsTo(Customer::class, 'second_customer_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
