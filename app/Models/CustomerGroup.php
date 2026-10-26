<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerGroup extends Model
{
    protected $fillable = ['tenant_id', 'parent_id', 'name', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
}
