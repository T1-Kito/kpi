<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SqlServerGrammar;
use Illuminate\Support\Fluent;

/**
 * SQL Server forbids cycles/multiple cascade paths, including self SET NULL.
 * Keep every FK enforced; reject deletion of referenced rows instead of dropping FKs.
 * Application workflows must explicitly detach/delete dependents inside a transaction.
 */
class SqlServerRestrictGrammar extends SqlServerGrammar
{
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        $safe=clone $command;
        if($safe->onDelete)$safe->onDelete='no action';
        if($safe->onUpdate)$safe->onUpdate='no action';
        return parent::compileForeign($blueprint,$safe);
    }
}
