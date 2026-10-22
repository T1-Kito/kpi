<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $db=DB::connection();
        if($db->getDriverName()!=='sqlsrv')return;
        $grammar=$db->getQueryGrammar();
        $db->transaction(function()use($db,$grammar){
            foreach(['customers'=>['tax_code','customers_tenant_id_tax_code_unique'],'chat_conversations'=>['direct_key','chat_conversations_tenant_id_direct_key_unique']] as $table=>[$column,$indexName]) {
                $object=str_replace("'","''",$db->getTablePrefix().$table);
                $indexName=($db->getConfig('prefix_indexes')?$db->getTablePrefix():'').$indexName;
                $literal=str_replace("'","''",$indexName);
                $index=$grammar->wrap($indexName); $wrapped=$grammar->wrapTable($table); $key=$grammar->wrap($column);
                $db->unprepared("IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'$object') AND name=N'$literal') DROP INDEX $index ON $wrapped;");
                $db->unprepared("CREATE UNIQUE INDEX $index ON $wrapped ([tenant_id], $key) WHERE $key IS NOT NULL;");
            }
        });
    }
    public function down(): void
    {
        if(DB::getDriverName()==='sqlsrv')throw new RuntimeException('Automatic downgrade would restore broken NULL uniqueness. Use a reviewed restore plan.');
    }
};
