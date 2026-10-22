<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CodeGenerator
{
    public function next(string $table, string $column, string $prefix, ?Closure $scope = null, int $digits = 5): string
    {
        $query = DB::table($table);
        if ($scope) $scope($query);
        $namespace = hash('sha256',json_encode([$table,$column,$prefix,$digits,$query->toSql(),$query->getBindings()]));
        return DB::transaction(function () use ($namespace,$query,$column,$prefix,$digits,$table) {
            // Upsert creates the lock row without resetting an existing counter.
            DB::table('code_sequences')->upsert([['namespace'=>$namespace,'last_value'=>0]],['namespace'],['namespace']);
            $sequence = DB::table('code_sequences')->where('namespace',$namespace)->lockForUpdate()->first();
            $last = (int)$sequence->last_value;
            if ($last === 0) {
                foreach ((clone $query)->where($column,'like',$prefix.'%')->pluck($column) as $existing) {
                    $suffix = substr($existing,strlen($prefix));
                    if (ctype_digit($suffix)) $last = max($last,(int)$suffix);
                }
            }
            for ($index=$last+1; $index<1000000; $index++) {
                $code=$prefix.str_pad((string)$index,$digits,'0',STR_PAD_LEFT);
                if (!(clone $query)->where($column,$code)->exists()) {
                    DB::table('code_sequences')->where('namespace',$namespace)->update(['last_value'=>$index]);
                    return $code;
                }
            }
            throw new RuntimeException("Unable to generate a unique code for {$table}.{$column}");
        },5);
    }
}
