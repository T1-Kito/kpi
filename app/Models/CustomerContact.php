<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContact extends Model
{
    protected $fillable = ['customer_id', 'name', 'phone', 'email', 'position', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(function (self $contact) {
            if ($contact->is_primary) {
                // Compatibility fields are a projection, not a second contact record.
                $projection = ['contact_name' => $contact->name];
                foreach (['phone', 'email'] as $field) {
                    if (array_key_exists($field, $contact->getAttributes())) $projection[$field] = $contact->$field;
                }
                $contact->customer()->update($projection);
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
