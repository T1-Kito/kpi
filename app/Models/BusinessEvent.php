<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessEvent extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $fillable = [
        'tenant_id',
        'event_type',
        'source_type',
        'source_id',
        'payload',
        'status',
        'retry_count',
        'last_attempted_at',
        'next_attempt_at',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_attempted_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
