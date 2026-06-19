<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'tenant_id', 'code', 'name', 'tax_code', 'phone', 'email', 'address', 'contact_name', 'terms', 'payment_terms',
        'bank_name', 'bank_account_no', 'bank_account_name', 'rating', 'supplied_products', 'status',
    ];
}
