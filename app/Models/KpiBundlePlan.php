<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiBundlePlan extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $guarded = ['id'];
    protected function casts(): array { return ['audience_id' => 'integer', 'starts_on' => 'date']; }
    public function bundle() { return $this->belongsTo(KpiBundle::class, 'kpi_bundle_id'); }
}
