<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintTemplate extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'module',
        'content_html',
        'merge_fields',
        'file_path',
        'file_name',
        'is_default',
        'status',
    ];

    protected $casts = [
        'merge_fields' => 'array',
        'is_default' => 'boolean',
    ];
}
