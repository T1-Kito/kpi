<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;
    use \App\Models\Concerns\HasVietnameseSearch;

    protected $fillable = ['tenant_id', 'code', 'name', 'phone', 'email', 'source', 'campaign_code', 'assigned_to', 'customer_id', 'status', 'needs','region','expected_service_date','guest_count','interested_services','notes','handover_to','handover_from','handover_status','workflow_history','merged_into_id'];
    protected $hidden = ['search_text'];
    protected function casts(): array { return ['workflow_history'=>'array','guest_count'=>'integer','handover_to'=>'integer','handover_from'=>'integer']; }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function nextTask(): HasOne
    {
        return $this->hasOne(Task::class, 'source_id')
            ->where('source_type', 'Lead')
            ->whereIn('status', ['new', 'in_progress', 'overdue'])
            ->orderBy('due_at');
    }

    public function latestCompletedTask(): HasOne
    {
        return $this->hasOne(Task::class, 'source_id')->where('source_type', 'Lead')->where('status', 'completed')->latestOfMany();
    }
}
