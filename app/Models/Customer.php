<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;
    use \App\Models\Concerns\HasCatalogSharing;

    protected $casts = ['is_shared' => 'boolean'];
    protected $attributes = ['is_shared' => false];
    protected $appends = ['can_manage_sharing'];

    protected $fillable = [
        'tenant_id', 'code', 'name', 'customer_type', 'merged_into_id', 'tax_code', 'identity_number', 'contact_name', 'phone', 'email', 'billing_address', 'address',
        'legal_representative', 'representative_position', 'payment_terms', 'bank_name', 'bank_account_no', 'bank_account_name',
        'credit_limit', 'sales_owner_id', 'status','business_segment','customer_group','customer_audience','created_by','is_shared','customer_group_id',
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
