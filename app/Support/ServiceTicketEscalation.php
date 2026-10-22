<?php
namespace App\Support;

use App\Models\{ServiceTicket,User,Alert,VkNotification};
use Illuminate\Support\Facades\DB;

final class ServiceTicketEscalation
{
    public function run(): int
    {
        $count=0;
        Alert::where('source_type','ServiceTicket')->where('alert_type','like','service_%')->whereIn('status',['open','acknowledged'])
            ->whereExists(fn($q)=>$q->selectRaw('1')->from('service_tickets')->whereColumn('service_tickets.id','alerts.source_id')->whereColumn('service_tickets.tenant_id','alerts.tenant_id')->whereIn('service_tickets.status',['resolved','closed','cancelled']))->update(['status'=>'resolved']);
        ServiceTicket::whereNotIn('status',['closed','cancelled'])->chunkById(100,function($tickets) use(&$count) {
            foreach($tickets as $row) DB::transaction(function() use($row,&$count) {
                $ticket=ServiceTicket::whereKey($row->id)->lockForUpdate()->firstOrFail();
                $rules=ServiceTicketSla::rules($ticket);
                if(ServiceTicketSla::pausedAt($ticket))return;
                foreach(['response','resolution'] as $kind) {
                    $state=TicketDeadline::describe($ticket,$kind)['state'];
                    if(!in_array($state,['warning','overdue'],true))continue;
                    $recipients=array_unique(array_filter([$ticket->assignee_id,...($rules['recipient_ids'] ?? [])]));
                    foreach($recipients as $id) {
                        $user=User::where('tenant_id',$ticket->tenant_id)->whereKey($id)->where('is_active',true)->first();
                        if(!$user || !$user->hasPermission('service.ticket.view') || !TicketAccess::query($user)->whereKey($ticket->id)->exists())continue;
                        $alert=Alert::firstOrCreate(['tenant_id'=>$ticket->tenant_id,'recipient_id'=>$id,'source_type'=>'ServiceTicket','source_id'=>$ticket->id,'alert_type'=>'service_'.$kind.'_'.$state],[
                            'level'=>$state==='overdue'?'high':'medium','title'=>($state==='overdue'?'Quá hạn ':'Sắp đến hạn ').($kind==='response'?'phản hồi: ':'xử lý: ').$ticket->code,
                            'message'=>'Yêu cầu '.$ticket->code.' cần được kiểm tra và xử lý.','action_url'=>'/service-tickets','status'=>'open',
                        ]);
                        if(!$alert->wasRecentlyCreated)continue;
                        VkNotification::create(['tenant_id'=>$ticket->tenant_id,'recipient_id'=>$id,'source_type'=>'Alert','source_id'=>$alert->id,'title'=>$alert->title,'message'=>$alert->message,'action_url'=>'/service-tickets','status'=>'unread']);
                        $ticket->worklogs()->create(['user_id'=>null,'action'=>'sla_escalated','visibility'=>'internal','content'=>'Đã nhắc hạn '.($kind==='response'?'phản hồi':'xử lý').' cho '.$user->name.'.','worked_at'=>now()]);
                        $count++;
                    }
                }
            });
        });
        return $count;
    }
}
