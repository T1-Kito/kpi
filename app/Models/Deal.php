<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deal extends Model
{
    protected $fillable = [
        'tenant_id', 'code', 'lead_id', 'customer_id', 'owner_id', 'name', 'source', 'description', 'amount', 'probability',
        'expected_close_date', 'next_activity_at', 'stage', 'status', 'lost_reason', 'won_at', 'lost_at', 'version',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'probability' => 'decimal:2', 'expected_close_date' => 'date', 'next_activity_at' => 'datetime', 'won_at' => 'datetime', 'lost_at' => 'datetime'];
    }

    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function stageHistory(): HasMany { return $this->hasMany(DealStageHistory::class); }
    public function items(): HasMany { return $this->hasMany(DealItem::class); }
}
