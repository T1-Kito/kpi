<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOpportunitySource extends Model
{
    protected $fillable = ['tenant_id', 'code', 'name', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
}
