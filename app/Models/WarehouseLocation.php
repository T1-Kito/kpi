<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLocation extends Model
{
    protected $fillable = ['warehouse_id', 'code', 'name', 'status'];
}
