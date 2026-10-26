<?php
namespace App\Support;
use Illuminate\Http\Request;
final class DealNeeds
{
    public static function validate(Request $request):array
    {
        $data=$request->validate([
            'needs'=>['sometimes','nullable','array:purpose,group_size,guest_count,service_date,budget,special_requests,interests,service_ids'],
            'needs.purpose'=>['nullable','string','max:255'],'needs.group_size'=>['nullable','string','max:100'],
            'needs.guest_count'=>['nullable','integer','min:1','max:1000000'],'needs.service_date'=>['nullable','date_format:Y-m-d'],
            'needs.budget'=>['nullable','numeric','min:0','max:999999999999'],'needs.special_requests'=>['nullable','string','max:2000'],
            'needs.interests'=>['nullable','array','max:30'],'needs.interests.*'=>['required','string','max:310','distinct'],
            'needs.service_ids'=>['nullable','array','max:30'],'needs.service_ids.*'=>['required','integer','distinct'],
        ],['max'=>':attribute vượt giới hạn cho phép.','min'=>':attribute nhỏ hơn mức cho phép.','integer'=>':attribute phải là số nguyên.','numeric'=>':attribute phải là số.','date_format'=>':attribute phải là ngày hợp lệ.','array'=>':attribute không đúng cấu trúc.','distinct'=>'Dịch vụ quan tâm bị trùng.'],[
            'needs'=>'Nhu cầu','needs.purpose'=>'Mục đích','needs.group_size'=>'Quy mô nhóm','needs.guest_count'=>'Số khách','needs.service_date'=>'Ngày sử dụng dịch vụ','needs.budget'=>'Ngân sách','needs.special_requests'=>'Yêu cầu đặc biệt','needs.interests'=>'Dịch vụ quan tâm','needs.interests.*'=>'Dịch vụ quan tâm',
        ]);
        if(isset($data['needs']['service_ids'])){
            $old=$request->route('deal');$oldIds=$old instanceof \App\Models\Deal?($old->needs['service_ids'] ?? []):[];
            $ids=array_map('intval',$data['needs']['service_ids']);
            $all=\App\Models\ServiceInterest::where('tenant_id',$request->user()->tenant_id)->get()->keyBy('id');
            $names=[];
            foreach($ids as $id){
                $item=$all[$id] ?? null;$parent=$item?->parent_id?($all[$item->parent_id] ?? null):null;
                if(!$item || ($item->parent_id && !$parent) || ((!$item->is_active || ($parent && !$parent->is_active)) && !in_array($id,$oldIds,true)))throw \Illuminate\Validation\ValidationException::withMessages(['needs.service_ids'=>'Dịch vụ không hợp lệ hoặc đã ngừng sử dụng. Vui lòng chọn lại.']);
                $names[]=$parent?$parent->name.' / '.$item->name:$item->name;
            }
            $data['needs']['service_ids']=$ids;
            // Display snapshots, not commercial order lines; never auto-add these to a quote.
            $data['needs']['interests']=$names;
        }
        return $data;
    }
}
