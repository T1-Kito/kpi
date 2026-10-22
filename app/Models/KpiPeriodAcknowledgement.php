<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiPeriodAcknowledgement extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function closure() { return $this->belongsTo(KpiPeriodClosure::class, 'kpi_period_closure_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
