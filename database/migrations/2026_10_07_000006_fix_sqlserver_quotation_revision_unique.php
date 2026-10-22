<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $connection=DB::connection();
        if ($connection->getDriverName() !== 'sqlsrv') return;
        $grammar=$connection->getQueryGrammar();
        $table=$grammar->wrapTable('quotations');
        $object=str_replace("'","''",$connection->getTablePrefix().'quotations');
        $indexName=($connection->getConfig('prefix_indexes') ? $connection->getTablePrefix() : '').'quotations_revision_root_id_revision_number_unique';
        $index=$grammar->wrap($indexName);
        $literal=str_replace("'","''",$indexName);
        // SQL Server treats NULL as a value in a normal unique index. Independent
        // roots all start at (NULL, 1); only children in an actual revision family
        // must be unique. DDL is transactional; no quotation rows are rewritten.
        $connection->transaction(function () use ($connection,$table,$object,$index,$literal) {
            $connection->unprepared("IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'$object') AND name=N'$literal') DROP INDEX $index ON $table;");
            $connection->unprepared("CREATE UNIQUE INDEX $index ON $table ([revision_root_id], [revision_number]) WHERE [revision_root_id] IS NOT NULL;");
        });
    }

    public function down(): void
    {
        if (DB::getDriverName()==='sqlsrv') {
            throw new RuntimeException('Refusing to restore the broken NULL revision constraint. Restore a verified backup if a full downgrade is required.');
        }
    }
};
