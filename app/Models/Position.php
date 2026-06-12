<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    protected $fillable = ['tenant_id', 'code', 'name'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
