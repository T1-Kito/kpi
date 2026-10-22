<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesPipelineStage extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $fillable = ['tenant_id', 'code', 'name', 'probability', 'sort_order', 'type', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
}
