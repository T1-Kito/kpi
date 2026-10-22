<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $hidden = ['tax_identity'];

    protected static function booted(): void
    {
        static::saving(function (Supplier $supplier) {
            $supplier->tax_code = \App\Support\BusinessIdentity::tax($supplier->tax_code);
            $supplier->tax_identity = $supplier->tax_code === null ? null : hash('sha256',$supplier->tax_code);
            if ($supplier->tax_identity && static::where('tenant_id',$supplier->tenant_id)
                ->where('tax_identity',$supplier->tax_identity)->when($supplier->exists,fn($q)=>$q->whereKeyNot($supplier->id))->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['tax_code'=>'Mã số thuế đã có nhà cung cấp. Vui lòng dùng hồ sơ hiện có.']);
            }
        });
    }

    protected $fillable = [
        'tenant_id', 'code', 'name', 'tax_code', 'phone', 'email', 'address', 'contact_name', 'terms', 'payment_terms',
        'bank_name', 'bank_account_no', 'bank_account_name', 'rating', 'supplied_products', 'status',
    ];
}
