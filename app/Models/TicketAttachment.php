<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TicketAttachment extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;
    protected $fillable=['tenant_id','service_ticket_id','uploaded_by','original_name','path','mime','bytes','sha256'];
    protected $hidden=['path','sha256'];
    protected function casts():array { return ['uploaded_by'=>'integer','bytes'=>'integer']; }
}
