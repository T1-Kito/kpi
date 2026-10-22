<?php
namespace App\Support;

use App\Models\ServiceTicket;
use Carbon\CarbonInterface;

final class TicketDeadline
{
    public static function describe(ServiceTicket $ticket, string $kind, ?CarbonInterface $clock = null): array
    {
        $due = $kind === 'response' ? $ticket->response_due_at : $ticket->resolution_due_at;
        $finished = $kind === 'response' ? $ticket->first_responded_at : $ticket->resolved_at;
        if ($ticket->status === 'cancelled') return ['state'=>'cancelled','color'=>'neutral','label'=>'Đã hủy','remaining_minutes'=>null];
        if (!$due || !$ticket->created_at) return ['state'=>'unknown','color'=>'neutral','label'=>'Chưa có hạn','remaining_minutes'=>null];
        if (!$finished && in_array($ticket->status, ['resolved','closed'], true))
            return ['state'=>'unknown','color'=>'neutral','label'=>'Thiếu mốc hoàn tất','remaining_minutes'=>null];
        $remaining = $due->getTimestamp() - ($finished ?? $clock ?? now())->getTimestamp();
        if (!$finished && \App\Support\ServiceTicketSla::pausedAt($ticket)) return ['state'=>'paused','color'=>'neutral','label'=>'Tạm dừng theo chính sách','remaining_minutes'=>null];
        if ($finished) return ['state'=>$remaining >= 0 ? 'on_time' : 'late','color'=>$remaining >= 0 ? 'green' : 'red','label'=>$remaining >= 0 ? 'Đúng hạn' : 'Trễ hạn','remaining_minutes'=>null];
        $rules=\App\Support\ServiceTicketSla::rules($ticket);
        $budget = max(1, $due->getTimestamp() - $ticket->created_at->getTimestamp());
        $workingRemaining=$remaining>=0 ? ServiceCalendar::secondsBetween($clock ?? now(),$due,$rules) : $remaining;
        $warning=isset($rules['warning_before_minutes']) ? $rules['warning_before_minutes']*60 : $budget*.2;
        $state = $remaining < 0 ? 'overdue' : ($workingRemaining <= $warning ? 'warning' : 'normal');
        return ['state'=>$state,'color'=>['normal'=>'green','warning'=>'yellow','overdue'=>'red'][$state],
            'label'=>['normal'=>'Trong hạn','warning'=>'Sắp đến hạn','overdue'=>'Quá hạn'][$state],
            'remaining_minutes'=>$remaining < 0 ? -(int) ceil(abs($remaining)/60) : (int) ceil($remaining/60)];
    }
}
