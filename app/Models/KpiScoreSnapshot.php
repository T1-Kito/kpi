<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiScoreSnapshot extends Model
{
    protected $fillable = [
        'tenant_id',
        'snapshot_date',
        'period_type',
        'overall_score',
        'metrics',
        'status',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'metrics' => 'array',
            'locked_at' => 'datetime',
            'overall_score' => 'decimal:2',
        ];
    }
}
