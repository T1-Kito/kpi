<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketWorklog extends Model
{
    protected $fillable = ['service_ticket_id', 'user_id', 'action', 'visibility', 'content', 'minutes_spent', 'worked_at'];
    protected function casts(): array { return ['worked_at' => 'datetime']; }
    public function ticket(): BelongsTo { return $this->belongsTo(ServiceTicket::class, 'service_ticket_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
