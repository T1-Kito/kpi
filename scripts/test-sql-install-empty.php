<?php
require __DIR__.'/../deployment/installer/sql-install-preflight.php';
$objects=[['schema_name'=>'dbo','name'=>'migrations','type'=>'U']];
$columns=['id','migration','batch'];
$cases=[
    [[],false,[],null,true],
    [$objects,false,$columns,0,false],
    [$objects,true,$columns,0,true],
    [$objects,true,$columns,1,false],
    [$objects,true,['id','migration'],0,false],
    [[['schema_name'=>'dbo','name'=>'customers','type'=>'U']],true,$columns,0,false],
    [[['schema_name'=>'other','name'=>'migrations','type'=>'U']],true,$columns,0,false],
    [[...$objects,['schema_name'=>'dbo','name'=>'tenants','type'=>'U']],true,$columns,0,false],
    [[['schema_name'=>'dbo','name'=>'migrations','type'=>'V']],true,$columns,0,false],
];
foreach($cases as [$items,$confirmed,$cols,$rows,$expected])if(vkSqlEmptyDecision($items,$confirmed,$cols,$rows)!==$expected)throw new RuntimeException('Unsafe empty-schema decision');
echo "PASS: 9 empty-schema/retry decisions; existing data, partial tables, views and unconfirmed retries rejected.\n";
