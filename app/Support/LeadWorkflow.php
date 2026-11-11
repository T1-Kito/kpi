<?php
namespace App\Support;
use App\Models\{Lead, User, Task, VkNotification};
use Illuminate\Support\Facades\DB;
final class LeadWorkflow
{
    public static function visible($query, User $actor) {
        if ($actor->dataScope()==='company') return $query->whereNull('merged_into_id');
        return $query->whereNull('merged_into_id')->where(function($q) use($actor) {
            $q->where(fn($owned)=>DataScope::owned($owned,$actor,'assigned_to','assignee'));
            $q->orWhere('handover_to',$actor->id)->orWhere('handover_from',$actor->id);
        });
    }
    public static function duplicates(User $actor, array $data, ?int $except=null): array {
        $phone = PhoneNumber::canonical((string)($data['phone'] ?? ''));
        $email = mb_strtolower(trim($data['email'] ?? ''));
        return self::visible(Lead::where('tenant_id',$actor->tenant_id),$actor)->when($except,fn($q)=>$q->where('id','!=',$except))
            ->get(['id','code','name','phone','email'])->filter(fn($row)=>($phone && PhoneNumber::canonical((string)$row->phone)===$phone) || ($email && mb_strtolower(trim($row->email ?? ''))===$email))->values()->map->only(['id','code','name'])->all();
    }
    public static function history(Lead $lead, User $actor, string $action, array $details=[]): array {
        return [...($lead->workflow_history ?? []), ['action'=>$action,'at'=>now()->toIso8601String(),'actor_id'=>$actor->id,'actor_name'=>$actor->name,'details'=>$details]];
    }
    public function handover(Lead $lead, User $actor, int $recipient, string $note): Lead {
        return DB::transaction(function() use($lead,$actor,$recipient,$note) {
            \App\Models\Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
            $lead=Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            abort_unless($lead->tenant_id===$actor->tenant_id && DataScope::owned(Lead::whereKey($lead->id),$actor,'assigned_to','assignee')->exists(),404);
            abort_if($lead->merged_into_id || $lead->status==='qualified' || $lead->handover_status==='pending',409,'Hồ sơ đã chuyển đổi, đã gộp hoặc đang chờ tiếp nhận.');
            $user=User::where('tenant_id',$actor->tenant_id)->where('is_active',true)->findOrFail($recipient);
            abort_unless(LeadHandoverPool::allows($user,\App\Models\Tenant::findOrFail($actor->tenant_id)),422,'Người nhận không thuộc nhóm Sales được cấu hình nhận bàn giao.');
            abort_unless($user->hasPermission('sales.lead.manage') && $user->hasPermission('sales.deal.manage'),422,'Chọn nhân viên Sales có quyền quản lý lead và cơ hội.');
            abort_if($recipient===$actor->id,422,'Chọn người nhận khác người bàn giao.');
            abort_unless(trim($lead->needs ?? '') && $lead->expected_service_date && $lead->guest_count,422,'Bổ sung nhu cầu, ngày dự kiến và số khách trước khi bàn giao.');
            $before=$lead->only(['handover_to','handover_from','handover_status','assigned_to']);
            $lead->update(['handover_to'=>$recipient,'handover_from'=>$actor->id,'handover_status'=>'pending',
                'workflow_history'=>self::history($lead,$actor,'Bàn giao Sales',['recipient_id'=>$recipient,'recipient_name'=>$user->name,'note'=>$note])]);
            $this->notify($lead,$recipient,'Lead chờ bạn tiếp nhận');
            app(AuditLogger::class)->record('lead',$lead->id,'handover_lead',$actor,$before,$lead->only(['handover_to','handover_from','handover_status','assigned_to']),null,$note);
            return $lead->refresh();
        });
    }
    public function respond(Lead $lead, User $actor, string $decision, string $note): Lead {
        return DB::transaction(function() use($lead,$actor,$decision,$note) {
            \App\Models\Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
            $lead=Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            abort_unless($lead->tenant_id===$actor->tenant_id,404);
            abort_if($lead->merged_into_id || $lead->status==='qualified',409,'Hồ sơ đã được gộp hoặc chuyển đổi.');
            abort_unless((int)$lead->handover_to===(int)$actor->id && $lead->handover_status==='pending',409,'Hồ sơ không chờ bạn tiếp nhận hoặc đã được xử lý.');
            $before=$lead->only(['handover_status','assigned_to']);
            if ($decision==='accepted') {
                abort_unless($actor->is_active && $actor->hasPermission('sales.deal.manage'),403);
                app(LeadService::class)->assign($lead,$actor,$actor->id);
            }
            $lead->update(['handover_status'=>$decision,'workflow_history'=>self::history($lead,$actor,$decision==='accepted'?'Sales đã tiếp nhận':'Sales trả lại',['note'=>$note])]);
            $this->notify($lead,(int)$lead->handover_from,$decision==='accepted'?'Sales đã tiếp nhận lead':'Lead được Sales trả lại');
            app(AuditLogger::class)->record('lead',$lead->id,$decision==='accepted'?'accept_lead_handover':'return_lead_handover',$actor,$before,$lead->only(['handover_status','assigned_to']),null,$note);
            return $lead->refresh();
        });
    }
    public function merge(Lead $source, Lead $target, User $actor, string $reason): Lead {
        return DB::transaction(function() use($source,$target,$actor,$reason) {
            \App\Models\Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
            $records=Lead::whereIn('id',[$source->id,$target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source=$records[$source->id];$target=$records[$target->id];
            foreach([$source,$target] as $row) {
                abort_unless($row->tenant_id===$actor->tenant_id && DataScope::owned(Lead::whereKey($row->id),$actor,'assigned_to','assignee')->exists(),404);
                abort_if($row->merged_into_id || $row->status==='qualified' || $row->handover_status==='pending',409,'Không gộp hồ sơ đã chuyển đổi, đã gộp hoặc đang bàn giao.');
                abort_if(\App\Models\Deal::where('lead_id',$row->id)->exists() || \App\Models\Quotation::where('lead_id',$row->id)->exists(),409,'Hồ sơ đã có chứng từ. Cần đối chiếu riêng, không tự gộp.');
            }
            abort_if($source->id===$target->id,422,'Chọn hai hồ sơ khác nhau.');
            $samePhone=filled($source->phone) && PhoneNumber::canonical($source->phone)===PhoneNumber::canonical($target->phone);
            $sameEmail=$source->email && mb_strtolower($source->email)===mb_strtolower($target->email ?? '');
            abort_unless($samePhone || $sameEmail,422,'Chỉ gộp hồ sơ trùng điện thoại hoặc email đã đối chiếu.');
            $before=$source->toArray();
            foreach(['needs','region','expected_service_date','guest_count','interested_services','notes'] as $field) if (empty($target->$field) && !empty($source->$field)) $target->$field=$source->$field;
            $target->workflow_history=[...($target->workflow_history ?? []),...($source->workflow_history ?? []),
                ['action'=>'Gộp hồ sơ','at'=>now()->toIso8601String(),'actor_name'=>$actor->name,'actor_id'=>$actor->id,'details'=>['source_code'=>$source->code,'reason'=>$reason,'source_snapshot'=>$before]]];
            $target->save();
            Task::where('tenant_id',$actor->tenant_id)->where('source_type','Lead')->where('source_id',$source->id)->update(['source_id'=>$target->id]);
            \App\Models\Alert::where('tenant_id',$actor->tenant_id)->where('source_type','Lead')->where('source_id',$source->id)->update(['source_id'=>$target->id]);
            $source->update(['merged_into_id'=>$target->id,'workflow_history'=>self::history($source,$actor,'Đã gộp vào '.$target->code,['reason'=>$reason])]);
            app(AuditLogger::class)->record('lead',$target->id,'merge_leads',$actor,['source'=>$before],['target_id'=>$target->id],null,$reason);
            return $target->refresh();
        });
    }
    private function notify(Lead $lead,int $recipient,string $title): void {
        VkNotification::create(['tenant_id'=>$lead->tenant_id,'recipient_id'=>$recipient,'title'=>$title,'message'=>$lead->code.' - '.$lead->name,'source_type'=>'Lead','source_id'=>$lead->id,'action_url'=>'/leads?open='.$lead->id,'status'=>'unread']);
    }
}
