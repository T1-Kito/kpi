<?php
namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Pure planning rules shared by catalog adapters. Never writes business data. */
final class ImportRevision
{
    public static function hash(array $values): string
    {
        ksort($values);
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    }
    public static function plan(?array $link,int $revision,array $incoming,array $current,bool $allowsUpdate): array
    {
        if($revision<1 || $revision>2147483647)self::fail('Phiên bản nguồn phải là số nguyên từ 1 đến 2147483647.');
        $sourceHash=self::hash($incoming);
        if($link===null)return ['action'=>'create','values'=>$incoming,'source_hash'=>$sourceHash,'changes'=>[]];
        if($revision<(int)$link['source_revision'])self::fail('Phiên bản nguồn cũ hơn lần nhập gần nhất.');
        if(!hash_equals($link['crm_hash'],self::hash($current)))self::fail('Hồ sơ CRM đã được sửa sau lần nhập. Cần đối chiếu trước khi cập nhật.');
        if($revision===(int)$link['source_revision']) {
            if(!hash_equals($link['source_hash'],$sourceHash))self::fail('Cùng phiên bản nhưng nội dung khác. Hãy tăng phiên bản nguồn.');
            return ['action'=>'skip','values'=>$current,'source_hash'=>$sourceHash,'changes'=>[]];
        }
        if(!$allowsUpdate)self::fail('Danh mục này chỉ được thêm mới qua Excel.');
        $values=$current;$changes=[];
        foreach($incoming as $field=>$value){
            // An empty cell means preserve, never erase an existing CRM value.
            if($value===null || (is_string($value) && trim($value)===''))continue;
            if(!array_key_exists($field,$current))self::fail('File có trường không được phép cập nhật.');
            if($current[$field]!==$value){$changes[$field]=['before'=>$current[$field],'after'=>$value];$values[$field]=$value;}
        }
        return ['action'=>'update','values'=>$values,'source_hash'=>$sourceHash,'changes'=>$changes];
    }
    private static function fail(string $message): never {throw ValidationException::withMessages(['file'=>$message]);}
}
