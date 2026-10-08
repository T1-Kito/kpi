<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealStageHistory extends Model
{
    protected $fillable = ['deal_id', 'from_stage', 'to_stage', 'changed_by', 'reason', 'changed_at'];
    protected function casts(): array { return ['changed_at' => 'datetime']; }
    public function deal(): BelongsTo { return $this->belongsTo(Deal::class); }
    public function changedBy(): BelongsTo { return $this->belongsTo(User::class, 'changed_by'); }
}
