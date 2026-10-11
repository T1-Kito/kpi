<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiAssignment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['audience_id' => 'integer', 'target_value' => 'decimal:2', 'weight' => 'decimal:2', 'locked_results' => 'array', 'locked_at' => 'datetime', 'period_start' => 'date', 'period_end' => 'date'];
    }

    public function definition()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    public function bundle()
    {
        return $this->belongsTo(KpiBundle::class, 'kpi_bundle_id');
    }
}
