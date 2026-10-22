<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiBundleItem extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['target_value' => 'decimal:2', 'weight' => 'decimal:2', 'sort_order' => 'integer'];
    }

    public function definition()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }
}
