<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskStatusHistory extends Model
{
    protected $fillable = ['task_id', 'from_status', 'to_status', 'changed_by', 'reason'];

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
