<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = ['code', 'name', 'logo_path', 'status'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
