<?php
function vkSqlDiagnostic(Throwable $exception,string $stage,?string $migration): array {
    $sqlstate=null;$native=null;$reason='UNKNOWN';
    for($current=$exception,$depth=0;$current!==null&&$depth<10;$current=$current->getPrevious(),$depth++){
        if($current instanceof PDOException){
            $info=$current->errorInfo??[];
            if(isset($info[0])&&preg_match('/^[A-Z0-9]{5}$/D',(string)$info[0]))$sqlstate=(string)$info[0];
            if(isset($info[1])&&is_numeric($info[1]))$native=(int)$info[1];
        }
        $text=strtolower($current->getMessage());
        foreach(['multiple cascade paths'=>'FOREIGN_KEY_CASCADE','already an object named'=>'OBJECT_EXISTS','query timeout'=>'TIMEOUT','login timeout'=>'TIMEOUT','invalid column name'=>'COLUMN','invalid object name'=>'OBJECT_MISSING','permission was denied'=>'PERMISSION','login failed'=>'LOGIN','certificate'=>'TLS'] as $needle=>$code)
            if(str_contains($text,$needle)){$reason=$code;break;}
    }
    $migration=$migration!==null&&preg_match('/^[A-Za-z0-9_]+$/D',$migration)?$migration:null;
    return ['stage'=>preg_match('/^[A-Z_]+$/D',$stage)?$stage:'UNKNOWN','migration'=>$migration,'sqlstate'=>$sqlstate,'native'=>$native,'reason'=>$reason];
}
