<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyClaim extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $hidden = ['registration_identity'];

    protected static function booted(): void
    {
        static::saving(function (WarrantyClaim $claim) {
            $claim->serial_number = mb_strtoupper(trim($claim->serial_number));
            $claim->product_name = trim($claim->product_name);
            $claim->registration_identity = \App\Support\BusinessIdentity::warranty($claim->product_name,$claim->serial_number);
            if (static::where('tenant_id',$claim->tenant_id)->where('registration_identity',$claim->registration_identity)
                ->when($claim->exists,fn($q)=>$q->whereKeyNot($claim->id))->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['serial_numbers'=>'Serial đã được đăng ký cho sản phẩm này. Vui lòng tra cứu hồ sơ hiện có.']);
            }
        });
    }

    public function getWarrantyStatusAttribute($stored): string
    {
        if (!$this->warranty_start_at || !$this->warranty_end_at) return $stored ?? 'out_of_warranty';
        $today = now()->startOfDay();
        if ($today->lt($this->warranty_start_at)) return 'not_started';
        return $today->lte($this->warranty_end_at) ? 'in_warranty' : 'out_of_warranty';
    }
    protected $fillable = ['tenant_id', 'customer_id', 'tax_code', 'customer_name', 'customer_phone', 'customer_email', 'customer_address', 'serial_number', 'product_name', 'purchase_date', 'warranty_start_at', 'warranty_months', 'warranty_end_at', 'service_ticket_id', 'created_by', 'code', 'issue_description', 'warranty_status', 'status', 'decision_note', 'resolved_at'];
    protected function casts(): array { return ['resolved_at' => 'datetime', 'purchase_date' => 'date', 'warranty_start_at' => 'date', 'warranty_end_at' => 'date']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class)->withoutGlobalScope('catalog_visibility'); }
    public function ticket(): BelongsTo { return $this->belongsTo(ServiceTicket::class, 'service_ticket_id'); }
}
