<?php
namespace App\Support;

use App\Models\{ServiceTicket,SlaPolicy,User};
use Illuminate\Support\Facades\DB;

final class ServiceTicketSla
{
    public static function rules(ServiceTicket $ticket): array
    {
        if (!$ticket->exists) return ['clock'=>'continuous','allow_pause'=>false];
        $log=$ticket->slaEvents->firstWhere('action','sla_policy');
        return $log ? (json_decode($log->content,true) ?: []) : ['clock'=>'continuous','allow_pause'=>false];
    }

    public static function pausedAt(ServiceTicket $ticket): ?\Carbon\CarbonInterface
    {
        if (!$ticket->exists) return null;
        $latest=$ticket->slaEvents->whereIn('action',['sla_paused','sla_resumed'])->sortByDesc('id')->first();
        return $latest?->action==='sla_paused' ? $latest->worked_at : null;
    }

    public static function selected(User $actor,string $priority): ?SlaPolicy
    {
        return SlaPolicy::where('tenant_id',$actor->tenant_id)->where('module','service')->where('task_type','ticket')->where('priority',$priority)->first();
    }

    public static function pause(ServiceTicket $ticket, User $actor, bool $pause, string $reason): ServiceTicket
    {
        return DB::transaction(function() use($ticket,$actor,$pause,$reason) {
            $ticket=ServiceTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            TicketAccess::authorize($actor,$ticket);
            abort_unless(trim($reason)!=='',422,'Cần ghi lý do tạm dừng hoặc tiếp tục SLA.');
            abort_if(in_array($ticket->status,['resolved','closed','cancelled'],true),422,'Hồ sơ đã xử lý hoặc kết thúc.');
            $rules=self::rules($ticket); $paused=self::pausedAt($ticket);
            abort_unless($rules['allow_pause'] ?? false,422,'Chính sách lúc tiếp nhận không cho phép tạm dừng SLA.');
            abort_if($pause ? $paused!==null : $paused===null,422,'Trạng thái tạm dừng SLA không phù hợp.');
            if ($pause) {
                abort_unless($ticket->status==='pending_customer',422,'Chỉ tạm dừng khi đang chờ thông tin khách hàng.');
                abort_unless($ticket->first_responded_at,422,'Ghi nhận phản hồi đầu tiên cho khách trước khi tạm dừng chờ thông tin.');
                abort_if(($ticket->resolution_due_at && $ticket->resolution_due_at->lt(now())) || (!$ticket->first_responded_at && $ticket->response_due_at?->lt(now())),422,'Không tạm dừng SLA đã quá hạn.');
            } else {
                $seconds=ServiceCalendar::secondsBetween($paused,now(),$rules);
                foreach (['response_due_at','resolution_due_at'] as $field) {
                    if ($field==='response_due_at' && $ticket->first_responded_at) continue;
                    if ($ticket->$field) $ticket->$field=ServiceCalendar::addSeconds($ticket->$field,$seconds,$rules);
                }
                $ticket->save();
            }
            $ticket->worklogs()->create(['user_id'=>$actor->id,'action'=>$pause?'sla_paused':'sla_resumed','visibility'=>'internal','content'=>($pause?'Tạm dừng SLA: ':'Tiếp tục SLA: ').trim($reason),'worked_at'=>now()]);
            app(AuditLogger::class)->record('service_ticket',$ticket->id,$pause?'pause_sla':'resume_sla',$actor,null,['reason'=>$reason,'response_due_at'=>$ticket->response_due_at,'resolution_due_at'=>$ticket->resolution_due_at]);
            return $ticket->fresh();
        });
    }
}
