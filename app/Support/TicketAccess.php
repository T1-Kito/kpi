<?php
namespace App\Support;
use App\Models\{ServiceTicket,User};
use Illuminate\Database\Eloquent\Builder;
final class TicketAccess
{
    public static function query(User $actor):Builder {
        $query=ServiceTicket::where('tenant_id',$actor->tenant_id);
        if($actor->dataScope()!=='company') $query->where(fn($q)=>$q->where('opened_by',$actor->id)->orWhere('assignee_id',$actor->id));
        return $query;
    }
    public static function authorize(User $actor,ServiceTicket $ticket):void { abort_unless(self::query($actor)->whereKey($ticket->id)->exists(),404); }
}
