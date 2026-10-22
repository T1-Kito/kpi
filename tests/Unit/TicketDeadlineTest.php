<?php
namespace Tests\Unit;
use App\Models\ServiceTicket;
use App\Support\TicketDeadline;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TicketDeadlineTest extends TestCase
{
    public function test_deadline_boundaries_and_finished_results(): void
    {
        $ticket = new ServiceTicket(['status'=>'open','resolution_due_at'=>'2026-10-20 20:00:00','response_due_at'=>'2026-10-20 12:00:00']);
        $ticket->created_at = Carbon::parse('2026-10-20 10:00:00');
        foreach (['17:59:59'=>'green','18:00:00'=>'yellow','20:00:00'=>'yellow','20:00:01'=>'red'] as $time=>$color)
            $this->assertSame($color, TicketDeadline::describe($ticket,'resolution',Carbon::parse('2026-10-20 '.$time))['color']);
        $ticket->resolved_at = Carbon::parse('2026-10-20 19:00:00'); $ticket->status = 'resolved';
        $this->assertSame('on_time',TicketDeadline::describe($ticket,'resolution',Carbon::parse('2026-12-20'))['state']);
        $ticket->resolved_at = Carbon::parse('2026-10-20 20:01:00');
        $this->assertSame('late',$ticket->resolution_sla['state']);
        $ticket->status = 'cancelled'; $this->assertSame('neutral',$ticket->resolution_sla['color']);
    }
    public function test_missing_dates_are_not_reported_as_success(): void
    {
        $ticket = new ServiceTicket(['status'=>'closed']);
        $this->assertSame('unknown',$ticket->resolution_sla['state']);
    }
}
