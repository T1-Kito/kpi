<?php

namespace App\Support;

use App\Models\{Customer, Sku, User};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Catalog sharing never grants permissions to business documents or other companies. */
final class CatalogAccess
{
    public static function actor(): ?User
    {
        return app()->bound('request') ? request()->attributes->get('catalog_actor') : null;
    }

    public static function full(User $user): bool
    {
        if ($user->isSpAdmin()) return true;
        $user->loadMissing('roles');
        return $user->roles->contains(fn ($role) => (int) $role->tenant_id === (int) $user->tenant_id && $role->code === 'ROLE-ADMIN');
    }

    public static function visible(Builder $query, User $actor): Builder
    {
        $table = $query->getModel()->getTable();
        $query->where($table.'.tenant_id', $actor->tenant_id);
        if (!self::full($actor)) {
            $query->where(fn ($scope) => $scope->where($table.'.created_by', $actor->id)->orWhere($table.'.is_shared', true));
        }
        return $query;
    }

    public static function mayShare(Model $record, User $actor): bool
    {
        return (int) $record->tenant_id === (int) $actor->tenant_id
            && (self::full($actor) || (int) $record->created_by === (int) $actor->id);
    }

    public static function authorize(Model $record, User $actor): void
    {
        abort_unless(in_array($record::class, [Customer::class, Sku::class], true)
            && self::visible($record->newQueryWithoutScope('catalog_visibility'), $actor)->whereKey($record->id)->exists(), 404);
    }

    public static function validateReferences(\Illuminate\Http\Request $request): void
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) return;
        $ids = ['customer_id' => [], 'sku_id' => []];
        $scan = function (array $values) use (&$scan, &$ids): void {
            foreach ($values as $key => $value) {
                if (isset($ids[$key]) && (is_int($value) || (is_string($value) && ctype_digit($value)))) $ids[$key][] = (int) $value;
                elseif (is_array($value)) $scan($value);
            }
        };
        $scan($request->all());
        foreach (['customer_id' => Customer::class, 'sku_id' => Sku::class] as $key => $model) {
            $wanted = array_values(array_unique($ids[$key]));
            if ($wanted) abort_unless($model::whereIn('id', $wanted)->count() === count($wanted), 404, 'Khách hàng hoặc sản phẩm không thuộc phạm vi được phép sử dụng.');
        }
    }
}
