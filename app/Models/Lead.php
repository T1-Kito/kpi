<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    protected $fillable = ['tenant_id', 'code', 'name', 'phone', 'email', 'source', 'campaign_code', 'assigned_to', 'customer_id', 'status'];

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
}
