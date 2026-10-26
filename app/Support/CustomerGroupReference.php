<?php
namespace App\Support;

use App\Models\{Customer,CustomerGroup};
use Illuminate\Validation\ValidationException;

final class CustomerGroupReference
{
    public const LABELS=['group_level1'=>'Nhóm khách hàng cấp 1','group_level2'=>'Nhóm khách hàng cấp 2','group_level3'=>'Nhóm khách hàng cấp 3'];

    public static function names(?Customer $customer): array
    {
        $names=[];$id=$customer?->customer_group_id;$seen=[];
        while($id){
            if(isset($seen[$id]) || count($seen)>=3)self::fail('Phân nhóm hiện tại không hợp lệ. Liên hệ quản trị.');
            $seen[$id]=true;
            $group=CustomerGroup::where('tenant_id',$customer->tenant_id)->find($id);
            if(!$group)self::fail('Phân nhóm hiện tại không còn trong danh mục.');
            array_unshift($names,$group->name);$id=$group->parent_id;
        }
        return array_combine(array_keys(self::LABELS),array_pad($names,3,null));
    }

    public static function resolve(array $values,int $tenant,?Customer $existing=null): ?int
    {
        $names=array_map(fn($key)=>trim((string)($values[$key] ?? '')),array_keys(self::LABELS));
        if(!array_filter($names,fn($v)=>$v!==''))return $existing?->customer_group_id;
        if($names[0]==='' || ($names[2]!=='' && $names[1]===''))self::fail('Phân nhóm phải điền từ cấp 1, không được bỏ cấp ở giữa.');
        $groups=CustomerGroup::where('tenant_id',$tenant)->get();$parent=null;
        foreach($names as $name){
            if($name==='')break;
            if(mb_strlen($name)>100)self::fail('Tên nhóm khách hàng tối đa 100 ký tự.');
            $matches=$groups->filter(fn($g)=>(int)$g->parent_id===(int)$parent && mb_strtolower(trim($g->name))===mb_strtolower($name));
            if($matches->count()!==1)self::fail('Không tìm thấy duy nhất nhóm “'.$name.'” trong đúng nhánh. Nhập danh mục nhóm trước hoặc chọn lại theo mẫu.');
            $group=$matches->first();
            if(!$group->is_active && (int)$existing?->customer_group_id!==(int)self::lastId($names,$groups))self::fail('Nhóm “'.$name.'” đã ngừng sử dụng.');
            $parent=$group->id;
        }
        return $parent;
    }

    private static function lastId(array $names,$groups): ?int
    {
        $parent=null;
        foreach($names as $name){if($name==='')break;$group=$groups->first(fn($g)=>(int)$g->parent_id===(int)$parent && mb_strtolower($g->name)===mb_strtolower($name));if(!$group)return null;$parent=$group->id;}
        return $parent;
    }
    private static function fail(string $message): never {throw ValidationException::withMessages(['file'=>$message]);}
}
