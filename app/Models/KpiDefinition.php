<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KpiDefinition extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'source_type',
        'formula',
        'unit',
        'target_direction',
        'weight',
        'status',
    ];

    protected function casts(): array
    {
        return ['weight' => 'decimal:2'];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(KpiTarget::class);
    }
}
