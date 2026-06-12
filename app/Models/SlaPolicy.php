<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlaPolicy extends Model
{
    protected $fillable = [
        'tenant_id',
        'module',
        'task_type',
        'priority',
        'duration_minutes',
        'warning_before_minutes',
        'escalation_rules',
    ];

    protected function casts(): array
    {
        return ['escalation_rules' => 'array'];
    }
}
