<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'tenant_id', 'code', 'name', 'customer_type', 'merged_into_id', 'tax_code', 'identity_number', 'contact_name', 'phone', 'email', 'billing_address', 'address',
        'legal_representative', 'representative_position', 'payment_terms', 'bank_name', 'bank_account_no', 'bank_account_name',
        'credit_limit', 'sales_owner_id', 'status',
    ];

    public function salesOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class)->orderByDesc('is_primary')->orderBy('id');
    }
}
