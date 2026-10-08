<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DataScope
{
    /** @param Builder<\Illuminate\Database\Eloquent\Model> $query */
    public static function owned(
        Builder $query,
        User $user,
        ?string $ownerColumn = null,
        ?string $ownerRelation = null,
        ?string $departmentColumn = null,
        ?string $warehouseRelation = null,
    ): Builder {
        return match ($user->dataScope()) {
            'company' => $query,
            'department' => self::department($query, $user, $ownerColumn, $ownerRelation, $departmentColumn),
            'warehouse' => self::warehouse($query, $user, $ownerColumn, $warehouseRelation),
            default => $ownerColumn ? $query->where($ownerColumn, $user->id) : $query,
        };
    }

    /** @param Builder<\Illuminate\Database\Eloquent\Model> $query */
    public static function task(Builder $query, User $user): Builder
    {
        return match ($user->dataScope()) {
            'company' => $query,
            'department' => $query->where(function (Builder $scope) use ($user) {
                $scope->where('created_by', $user->id)->orWhere('assignee_id', $user->id);
                if ($user->department_id) {
                    $scope->orWhere('department_id', $user->department_id)
                        ->orWhereHas('assignee', fn (Builder $assignee) => $assignee->where('department_id', $user->department_id));
                }
            }),
            'warehouse' => $query->where(function (Builder $scope) use ($user) {
                $scope->where('assignee_id', $user->id)
                    ->orWhere('created_by', $user->id);
                if ($user->department_id) $scope->orWhere('department_id', $user->department_id);
                $scope->orWhere(fn (Builder $queue) => $queue->where('task_type', 'warehouse_issue')->whereNull('assignee_id'));
            }),
            default => $query->where(function (Builder $scope) use ($user) {
                $scope->where('assignee_id', $user->id)->orWhere('created_by', $user->id);
            }),
        };
    }

    /** @param Builder<\Illuminate\Database\Eloquent\Model> $query */
    public static function alert(Builder $query, User $user): Builder
    {
        if ($user->dataScope() === 'company') {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($user) {
            $scope->where('recipient_id', $user->id)->orWhereNull('recipient_id');
        });
    }

    /** @param Builder<\Illuminate\Database\Eloquent\Model> $query */
    public static function warehouseScope(Builder $query, User $user, string $warehouseRelation = 'warehouse'): Builder
    {
        if ($user->dataScope() !== 'warehouse') {
            return $query;
        }

        return $query->whereHas($warehouseRelation, fn (Builder $warehouse) => $warehouse->where('manager_id', $user->id));
    }

    /** @param Builder<\Illuminate\Database\Eloquent\Model> $query */
    private static function department(
        Builder $query,
        User $user,
        ?string $ownerColumn,
        ?string $ownerRelation,
        ?string $departmentColumn,
    ): Builder {
        return $query->where(function (Builder $scope) use ($user, $ownerColumn, $ownerRelation, $departmentColumn) {
            if ($departmentColumn && $user->department_id) {
                $scope->where($departmentColumn, $user->department_id);
            }

            if ($ownerRelation && $user->department_id) {
                $scope->orWhereHas($ownerRelation, fn (Builder $owner) => $owner->where('department_id', $user->department_id));
            } elseif ($ownerColumn) {
                $scope->orWhere($ownerColumn, $user->id);
            }
        });
    }

    /** @param Builder<\Illuminate\Database\Eloquent\Model> $query */
    private static function warehouse(
        Builder $query,
        User $user,
        ?string $ownerColumn,
        ?string $warehouseRelation,
    ): Builder {
        return $query->where(function (Builder $scope) use ($user, $ownerColumn, $warehouseRelation) {
            if ($ownerColumn) {
                $scope->where($ownerColumn, $user->id);
            }

            if ($warehouseRelation) {
                $scope->orWhereHas($warehouseRelation, fn (Builder $warehouse) => $warehouse->where('manager_id', $user->id));
            }
        });
    }
}
