<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'title',
        'description',
        'module',
        'task_type',
        'priority',
        'source_type',
        'source_id',
        'assignee_id',
        'department_id',
        'due_at',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class);
    }
}
