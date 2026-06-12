<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CodeGenerator
{
    public function next(string $table, string $column, string $prefix, ?Closure $scope = null, int $digits = 5): string
    {
        for ($index = 1; $index < 1000000; $index++) {
            $code = $prefix.str_pad((string) $index, $digits, '0', STR_PAD_LEFT);

            $query = DB::table($table);
            if ($scope) {
                $scope($query);
            }

            if (! $query->where($column, $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException("Unable to generate a unique code for {$table}.{$column}");
    }
}
