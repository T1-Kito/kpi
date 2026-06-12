<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = ['tenant_id', 'code', 'name', 'address', 'manager_id', 'status'];

    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class);
    }
}
