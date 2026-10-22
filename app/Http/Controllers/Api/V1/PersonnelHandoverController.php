<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{User, Tenant, Customer, Lead, Deal, Task, Approval, AuditLog, VkNotification};
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};

class PersonnelHandoverController extends Controller
{
    private const GROUPS = [
        'customers' => [Customer::class, 'sales_owner_id', 'master.view'],
        'leads' => [Lead::class, 'assigned_to', 'sales.lead.manage'],
        'deals' => [Deal::class, 'owner_id', 'sales.deal.manage'],
        'tasks' => [Task::class, 'assignee_id', 'task.view'],
    ];

    private function authorizeSource(Request $request, User $user): void
    {
        \App\Support\SystemAccount::protect($user);
        abort_unless($request->user()->dataScope() === 'company' && $request->user()->hasPermission('master.manage'), 403,
            'Bàn giao cần quyền quản lý dữ liệu nền và phạm vi toàn công ty.');
        abort_unless($user->tenant_id === $request->user()->tenant_id, 404);
    }

    private function query(User $user, string $group)
    {
        [$model, $owner] = self::GROUPS[$group];
        $query = $model::where('tenant_id', $user->tenant_id)->where($owner, $user->id);
        return match ($group) {
            'customers' => $query->whereNull('merged_into_id'),
            'leads' => $query->whereNotIn('status', ['qualified', 'converted', 'lost', 'disqualified']),
            'deals' => $query->where('status', 'open'),
            // Approval responsibilities are deliberately excluded.
            'tasks' => $query->whereIn('status', ['new','in_progress','overdue'])->where(function ($q) {
                $q->whereNull('task_type')->orWhere('task_type', 'not like', '%approv%');
            })->where(function ($q) {
                $q->whereNull('source_type')->orWhereIn('source_type',['','Lead','Deal','Customer']);
            }),
        };
    }

    public function preview(Request $request, User $user)
    {
        $this->authorizeSource($request, $user);
        $data=[]; $snapshot=[];
        foreach (self::GROUPS as $group => $_) {
            $rows=$this->query($user,$group)->orderBy('id')->limit(501)->get();
            abort_if($rows->count()>500,422,'Có hơn 500 hồ sơ trong một nhóm. Cần chia đợt bàn giao trước khi thực hiện.');
            $snapshot[$group]=$rows->mapWithKeys(fn ($row)=>[$row->id=>hash('sha256',$row->toJson())])->all();
            $data[$group]=$rows->map(fn ($row)=>['id'=>$row->id,'code'=>$row->code,'name'=>$row->name ?? $row->title,'status'=>$row->status,'source_type'=>$row->source_type ?? null,'source_id'=>$row->source_id ?? null])->all();
        }
        $history=AuditLog::where('tenant_id',$user->tenant_id)->where('entity_type','personnel_handover')->where('entity_id',$user->id)->latest('id')->limit(20)->get(['id','new_value','reason','created_at']);
        return response()->json(['data'=>$data,'meta'=>[
            'preview_token'=>Crypt::encryptString(json_encode(['actor'=>$request->user()->id,'source'=>$user->id,'expires'=>now()->addMinutes(15)->timestamp,'snapshot'=>$snapshot])),
            'recipients'=>User::where('tenant_id',$user->tenant_id)->where('is_active',true)->whereKeyNot($user->id)->get(['id','name','email']),
            'pending_approvals'=>Approval::where('tenant_id',$user->tenant_id)->where('approver_id',$user->id)->where('status','pending')->count(),
            'history'=>$history,
        ]]);
    }

    public function transfer(Request $request, User $user, AuditLogger $audit)
    {
        $this->authorizeSource($request,$user);
        $rules=['recipient_id'=>['required','integer'],'reason'=>['required','string','max:1000'],'preview_token'=>['required','string'],'selection'=>['required','array:customers,leads,deals,tasks']];
        foreach (self::GROUPS as $group=>$_) {
            $rules['selection.'.$group]=['sometimes','array','max:500'];
            $rules['selection.'.$group.'.*']=['integer','distinct','min:1'];
        }
        $data=$request->validate($rules);
        try {$preview=json_decode(Crypt::decryptString($data['preview_token']),true,512,JSON_THROW_ON_ERROR);}
        catch (\Throwable $e) {abort(422,'Bản xem trước không hợp lệ.');}
        abort_unless(($preview['actor']??null)===$request->user()->id && ($preview['source']??null)===$user->id && ($preview['expires']??0)>=now()->timestamp,409,'Bản xem trước đã hết hạn. Hãy mở lại.');
        return DB::transaction(function () use ($data,$preview,$request,$user,$audit) {
            Tenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $recipient=User::where('tenant_id',$user->tenant_id)->where('is_active',true)->whereKeyNot($user->id)->lockForUpdate()->find($data['recipient_id']);
            abort_unless($recipient,422,'Người nhận phải khác người bàn giao và đang hoạt động trong công ty.');
            $selected=[]; $count=0;
            foreach (self::GROUPS as $group=>[$model,$owner,$permission]) {
                $ids=$data['selection'][$group]??[];
                $rows=$this->query($user,$group)->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get();
                abort_unless($rows->count()===count($ids),409,'Hồ sơ đã đổi người phụ trách hoặc không còn đủ điều kiện. Hãy xem trước lại.');
                foreach ($rows as $row) {
                    abort_unless(($preview['snapshot'][$group][$row->id]??null)===hash('sha256',$row->toJson()),409,'Hồ sơ đã thay đổi sau khi xem trước. Hãy mở lại.');
                }
                if ($rows->isNotEmpty()) abort_unless($recipient->hasPermission($permission),422,'Người nhận chưa có quyền xử lý nhóm '.$group.'.');
                $selected[$group]=$rows;
                $count+=$rows->count();
            }
            abort_unless($count>0,422,'Chọn ít nhất một hồ sơ cần bàn giao.');
            // Follow-up tasks must travel with their Lead/Deal; standalone tasks are independent.
            foreach (['customers'=>'Customer','leads'=>'Lead','deals'=>'Deal'] as $group=>$type) {
                $ids=$selected[$group]->pluck('id')->all();
                $required=$this->query($user,'tasks')->where('source_type',$type)->whereIn('source_id',$ids)->pluck('id')->all();
                abort_if(array_diff($required,$selected['tasks']->pluck('id')->all()),422,'Hãy chọn cả công việc chăm sóc đang mở của Lead/cơ hội được bàn giao.');
            }
            foreach ($selected['tasks'] as $task) {
                $group=match($task->source_type) {'Customer'=>'customers','Lead'=>'leads','Deal'=>'deals',default=>null};
                if ($group) {
                    [$model,$owner]=self::GROUPS[$group];
                    $parent=$model::where('tenant_id',$user->tenant_id)->find($task->source_id);
                    abort_unless($parent && ($parent->$owner===$recipient->id || $selected[$group]->contains('id',$parent->id)),422,'Cần bàn giao cả Lead/cơ hội liên quan để người nhận có quyền chăm sóc.');
                }
            }
            $summary=[];
            foreach (self::GROUPS as $group=>[$model,$owner]) {
                $summary[$group]=$selected[$group]->pluck('id')->all();
                foreach ($selected[$group] as $row) {
                    $changes=[$owner=>$recipient->id];
                    if ($group==='tasks') $changes['department_id']=$recipient->department_id;
                    $row->update($changes);
                    if ($group==='tasks') {
                        VkNotification::where('tenant_id',$user->tenant_id)->where('recipient_id',$user->id)->where('source_type','Task')->where('source_id',$row->id)->where('status','unread')->update(['status'=>'read','read_at'=>now()]);
                        VkNotification::create(['tenant_id'=>$user->tenant_id,'recipient_id'=>$recipient->id,'title'=>'Công việc được bàn giao','message'=>$row->code.' - '.$row->title,'source_type'=>'Task','source_id'=>$row->id,'action_url'=>'/tasks','status'=>'unread']);
                    }
                    $audit->record($group,$row->id,'handover_owner',$request->user(),[$owner=>$user->id],$changes,$request,$data['reason']);
                }
            }
            $entry=$audit->record('personnel_handover',$user->id,'handover',$request->user(),null,['from_id'=>$user->id,'to_id'=>$recipient->id,'to_name'=>$recipient->name,'records'=>$summary],$request,$data['reason']);
            $url=count($summary['customers']) ? '/customers' : (count($summary['leads']) ? '/leads' : (count($summary['deals']) ? '/deals' : '/tasks'));
            VkNotification::create(['tenant_id'=>$user->tenant_id,'recipient_id'=>$recipient->id,'title'=>'Bạn nhận bàn giao hồ sơ','message'=>"Đã nhận {$count} hồ sơ/công việc từ {$user->name}. ".$data['reason'],'source_type'=>'PersonnelHandover','source_id'=>$entry->id,'action_url'=>$url,'status'=>'unread']);
            return response()->json(['data'=>['handover_id'=>$entry->id,'count'=>$count,'records'=>$summary],'message'=>'Đã bàn giao. Quyền duyệt và chứng từ cũ không thay đổi.']);
        });
    }
}
