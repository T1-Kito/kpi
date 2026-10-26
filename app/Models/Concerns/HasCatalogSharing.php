<?php

namespace App\Models\Concerns;

use App\Support\CatalogAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait HasCatalogSharing
{
    public static function bootHasCatalogSharing(): void
    {
        static::addGlobalScope('catalog_visibility', function (Builder $query) {
            if ($actor = CatalogAccess::actor()) CatalogAccess::visible($query, $actor);
        });
        static::creating(function (Model $record) {
            if (($actor = CatalogAccess::actor()) && (int) $record->tenant_id === (int) $actor->tenant_id) {
                $record->created_by = $actor->id;
            }
        });
    }

    public function getCanManageSharingAttribute(): bool
    {
        $actor = CatalogAccess::actor();
        return $actor && CatalogAccess::mayShare($this, $actor);
    }
}
