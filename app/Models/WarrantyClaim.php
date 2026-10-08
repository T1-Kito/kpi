<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyClaim extends Model
{
    protected $fillable = ['tenant_id', 'customer_id', 'tax_code', 'customer_name', 'customer_phone', 'customer_email', 'customer_address', 'serial_number', 'product_name', 'purchase_date', 'warranty_start_at', 'warranty_months', 'warranty_end_at', 'service_ticket_id', 'created_by', 'code', 'issue_description', 'warranty_status', 'status', 'decision_note', 'resolved_at'];
    protected function casts(): array { return ['resolved_at' => 'datetime', 'purchase_date' => 'date', 'warranty_start_at' => 'date', 'warranty_end_at' => 'date']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function ticket(): BelongsTo { return $this->belongsTo(ServiceTicket::class, 'service_ticket_id'); }
}
