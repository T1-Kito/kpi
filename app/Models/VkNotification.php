<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VkNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'tenant_id',
        'recipient_id',
        'title',
        'message',
        'source_type',
        'source_id',
        'action_url',
        'status',
        'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
