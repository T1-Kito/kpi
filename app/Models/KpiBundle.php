<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiBundle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'published_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(KpiBundleItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function plans()
    {
        return $this->hasMany(KpiBundlePlan::class)->orderByDesc('id');
    }
}
