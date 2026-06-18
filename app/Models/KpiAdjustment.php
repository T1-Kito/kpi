<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiAdjustment extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'kpi_definition_id',
        'created_by',
        'reviewed_by',
        'adjustment_type',
        'points',
        'period_type',
        'period_start',
        'period_end',
        'source_type',
        'source_id',
        'reason_code',
        'reason',
        'evidence_url',
        'status',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
