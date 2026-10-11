<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class OrganizationHierarchy
{
    /** Must be called while holding the tenant row lock during an organization update. */
    public static function assertAcyclic(Model $record, string $parentColumn, ?int $parentId): void
    {
        $visited = [(int) $record->id => true];
        while ($parentId) {
            if (isset($visited[$parentId])) {
                throw ValidationException::withMessages([$parentColumn => 'Không thể tạo vòng lặp trong cơ cấu tổ chức.']);
            }
            $visited[$parentId] = true;
            $parent = $record->newQuery()->where('tenant_id', $record->tenant_id)->find($parentId);
            if (! $parent) {
                throw ValidationException::withMessages([$parentColumn => 'Cấp quản lý phải thuộc doanh nghiệp hiện tại.']);
            }
            $parentId = $parent->{$parentColumn} ? (int) $parent->{$parentColumn} : null;
        }
    }
}
