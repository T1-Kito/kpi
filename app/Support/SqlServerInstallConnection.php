<?php

namespace App\Support;

use Illuminate\Database\SqlServerConnection;

/** New SQL installations can explicitly use a conservative, non-cascading FK policy. */
class SqlServerInstallConnection extends SqlServerConnection
{
    protected function getDefaultSchemaGrammar()
    {
        return $this->getConfig('restrict_foreign_key_deletes')
            ? new SqlServerRestrictGrammar($this)
            : parent::getDefaultSchemaGrammar();
    }
}
