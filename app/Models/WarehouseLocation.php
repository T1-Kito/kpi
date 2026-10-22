<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLocation extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    protected $fillable = ['warehouse_id', 'code', 'name', 'status'];
}
