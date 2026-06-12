<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $fillable = [
        'tenant_id',
        'recipient_id',
        'alert_type',
        'level',
        'title',
        'message',
        'source_type',
        'source_id',
        'action_url',
        'status',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    protected static function booted(): void
    {
        static::created(function (Alert $alert) {
            if (! $alert->recipient_id) {
                return;
            }

            VkNotification::firstOrCreate(
                [
                    'tenant_id' => $alert->tenant_id,
                    'recipient_id' => $alert->recipient_id,
                    'source_type' => 'Alert',
                    'source_id' => $alert->id,
                ],
                [
                    'title' => $alert->title,
                    'message' => $alert->message,
                    'action_url' => $alert->action_url ?: '/alerts',
                    'status' => 'unread',
                ],
            );
        });
    }
}
