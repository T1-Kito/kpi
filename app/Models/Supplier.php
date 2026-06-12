<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'phone',
        'email',
        'terms',
        'rating',
        'supplied_products',
        'status',
    ];
}
