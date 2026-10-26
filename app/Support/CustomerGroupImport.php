<?php

namespace App\Support;

use App\Models\CustomerGroup;
use Illuminate\Validation\ValidationException;

/** Imports paths, never moves, renames or disables existing groups. */
class CustomerGroupImport
{
    public const LABELS = ['level1'=>'Nhóm cấp 1', 'level2'=>'Nhóm cấp 2', 'level3'=>'Nhóm cấp 3'];

    public function plan(array $rows, $groups): array
    {
        $lookup=[]; $paths=[];
        foreach ($groups as $group) $lookup[(string)($group->parent_id ?? '').'|'.mb_strtolower($group->name)]=$group;
        $errors=[]; $preview=[]; $new=[]; $seen=[];
        foreach ($rows as $row) {
            $names=array_map(fn($key)=>trim((string)($row['data'][$key] ?? '')),array_keys(self::LABELS));
            $error=null;
            if (!$names[0]) $error='Cần nhập Nhóm cấp 1.';
            if ($names[2] && !$names[1]) $error='Có Nhóm cấp 3 thì phải nhập Nhóm cấp 2.';
            foreach ($names as $name) if (mb_strlen($name)>100) $error='Tên nhóm không được dài quá 100 ký tự.';
            $key=json_encode(array_map('mb_strtolower',$names),JSON_UNESCAPED_UNICODE);
            if (isset($seen[$key])) $error='Đường dẫn nhóm trùng dòng '.$seen[$key].'.';
            $seen[$key]=$row['row'];
            if ($error) { $errors[]=['row'=>$row['row'],'message'=>$error]; continue; }
            $parent=''; $parts=[]; $action='Đã có';
            foreach ($names as $name) {
                if ($name==='') break;
                $parts[]=$name;
                $match=$lookup[$parent.'|'.mb_strtolower($name)] ?? null;
                if ($match) {
                    if (!$match->is_active) { $error='Nhóm “'.$name.'” đã ngừng sử dụng. Vui lòng kiểm tra lại danh mục.'; break; }
                    $parent=(string)$match->id;
                } else {
                    $pathKey=json_encode(array_map('mb_strtolower',$parts),JSON_UNESCAPED_UNICODE);
                    if (!isset($paths[$pathKey])) {
                        $virtual='new:'.count($new);
                        $new[]=['ref'=>$virtual,'parent'=>$parent,'name'=>$name];
                        $paths[$pathKey]=$virtual;
                    }
                    $parent=$paths[$pathKey]; $action='Thêm mới';
                }
            }
            if ($error) $errors[]=['row'=>$row['row'],'message'=>$error];
            $preview[]=['row'=>$row['row'],'path'=>implode(' / ',array_filter($names,fn($v)=>$v!=='')),'action'=>$action];
        }
        if ($errors) throw ValidationException::withMessages(['file'=>array_map(fn($e)=>'Dòng '.$e['row'].': '.$e['message'],$errors)]);
        return ['rows'=>$preview,'new'=>$new,'create_count'=>count($new)];
    }

    public function snapshot($groups): string
    {
        return hash('sha256',json_encode($groups->sortBy('id')->values()->map(fn($g)=>[$g->id,$g->parent_id,$g->name,$g->is_active])->all()));
    }
}
