<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiTarget extends Model
{
    protected $fillable = [
        'tenant_id',
        'kpi_definition_id',
        'period_type',
        'period_start',
        'period_end',
        'target_value',
        'actual_value',
        'score',
        'status',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'locked_at' => 'datetime',
            'target_value' => 'decimal:2',
            'actual_value' => 'decimal:2',
            'score' => 'decimal:2',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }
}
