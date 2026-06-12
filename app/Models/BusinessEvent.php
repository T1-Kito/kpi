<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessEvent extends Model
{
    protected $fillable = [
        'tenant_id',
        'event_type',
        'source_type',
        'source_id',
        'payload',
        'status',
        'retry_count',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
