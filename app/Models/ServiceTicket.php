<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTicket extends Model
{
    protected $fillable = ['tenant_id', 'customer_id', 'serial_number', 'product_name', 'assignee_id', 'opened_by', 'code', 'subject', 'description', 'source', 'category', 'priority', 'status', 'first_responded_at', 'response_due_at', 'resolution_due_at', 'resolved_at', 'resolution_code', 'resolution_note', 'satisfaction_score'];
    protected function casts(): array { return ['first_responded_at' => 'datetime', 'response_due_at' => 'datetime', 'resolution_due_at' => 'datetime', 'resolved_at' => 'datetime', 'satisfaction_score' => 'integer']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assignee_id'); }
    public function worklogs(): HasMany { return $this->hasMany(TicketWorklog::class); }
}
