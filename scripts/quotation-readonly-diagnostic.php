<?php
if(PHP_SAPI!=='cli')exit(1);
$root=$argv[1]??'';
if(!is_file($root.'/artisan'))throw new RuntimeException('Invalid application root');
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $db=Illuminate\Support\Facades\DB::connection();
    $statuses=$db->table('quotations')->select('status')->selectRaw('COUNT(*) AS records')->groupBy('status')->get();
    $recent=$db->table('quotations')->orderByDesc('id')->limit(5)->get(['id','status','created_at','updated_at']);
    echo json_encode(['database'=>$db->getDatabaseName(),'statuses'=>$statuses,'recent'=>$recent],JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'READ_ONLY_DIAGNOSTIC_FAILED '.get_class($e).PHP_EOL);exit(1);}
