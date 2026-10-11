<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiPeriodClosure extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'member_ids' => 'array', 'snapshot' => 'array',
            'closed_at' => 'datetime', 'reopened_at' => 'datetime',
        ];
    }

    public function bundle()
    {
        return $this->belongsTo(KpiBundle::class, 'kpi_bundle_id');
    }

    public function acknowledgements()
    {
        return $this->hasMany(KpiPeriodAcknowledgement::class);
    }
}
