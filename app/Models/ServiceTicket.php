<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTicket extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $fillable = ['tenant_id', 'customer_id', 'serial_number', 'product_name', 'assignee_id', 'opened_by', 'code', 'subject', 'description', 'source', 'category', 'priority', 'status', 'first_responded_at', 'response_due_at', 'resolution_due_at', 'resolved_at', 'resolution_code', 'resolution_note', 'satisfaction_score', 'related_document', 'customer_confirmation'];
    protected $appends = ['response_sla', 'resolution_sla', 'sla_clock'];
    protected $hidden = ['slaEvents'];
    public function getSlaClockAttribute(): array { $rules=\App\Support\ServiceTicketSla::rules($this); return ['clock'=>$rules['clock'] ?? 'continuous','allow_pause'=>(bool)($rules['allow_pause'] ?? false),'paused'=>(bool)\App\Support\ServiceTicketSla::pausedAt($this)]; }
    public function getFillable() { return [...parent::getFillable(), 'document_type','document_id']; }
    public function getResponseSlaAttribute(): array { return \App\Support\TicketDeadline::describe($this, 'response'); }
    public function getResolutionSlaAttribute(): array { return \App\Support\TicketDeadline::describe($this, 'resolution'); }
    protected function casts(): array { return ['document_id'=>'integer','first_responded_at' => 'datetime', 'response_due_at' => 'datetime', 'resolution_due_at' => 'datetime', 'resolved_at' => 'datetime', 'satisfaction_score' => 'integer']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class)->withoutGlobalScope('catalog_visibility'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assignee_id'); }
    public function worklogs(): HasMany { return $this->hasMany(TicketWorklog::class); }
    public function slaEvents(): HasMany { return $this->worklogs()->whereIn('action',['sla_policy','sla_paused','sla_resumed']); }
    public function attachments():HasMany { return $this->hasMany(TicketAttachment::class); }
}
