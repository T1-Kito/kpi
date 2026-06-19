<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    protected $fillable = [
        'tenant_id', 'code', 'name', 'tax_code', 'contact_name', 'phone', 'email', 'billing_address', 'address',
        'legal_representative', 'representative_position', 'payment_terms', 'bank_name', 'bank_account_no', 'bank_account_name',
        'credit_limit', 'sales_owner_id', 'status',
    ];

    public function salesOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }
}
