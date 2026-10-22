<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $fillable = ['tenant_id', 'source_type', 'source_id', 'approver_id', 'status', 'reason', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
