<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{ServiceTicket,TicketAttachment};
use App\Support\{AuditLogger,TicketAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage};

class TicketAttachmentController extends Controller
{
    public function store(Request $request,ServiceTicket $ticket,AuditLogger $audit)
    {
        TicketAccess::authorize($request->user(),$ticket);
        $request->validate(['file'=>['required','file','image','mimes:jpg,jpeg,png,webp','max:5120','dimensions:max_width=6000,max_height=6000']]);
        $file=$request->file('file');$path=null;
        try {
            $result=DB::transaction(function()use($request,$ticket,$file,$audit,&$path){
                $locked=ServiceTicket::where('tenant_id',$request->user()->tenant_id)->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
                TicketAccess::authorize($request->user(),$locked);
                abort_if(in_array($locked->status,['closed','cancelled'],true),422,'Hồ sơ đã đóng/hủy, không thêm ảnh.');
                $query=TicketAttachment::where('tenant_id',$locked->tenant_id)->where('service_ticket_id',$locked->id);
                abort_if((clone $query)->count()>=10,422,'Tối đa 10 ảnh mỗi hồ sơ.');
                $hash=hash_file('sha256',$file->getRealPath());
                abort_if((clone $query)->where('sha256',$hash)->exists(),422,'Ảnh đã có trong hồ sơ.');
                $path=$file->store('ticket-evidence/'.$locked->tenant_id.'/'.$locked->id,'local');
                if(!$path)throw new \RuntimeException('Không lưu được ảnh. Chưa thay đổi hồ sơ.');
                $name=mb_substr(preg_replace('/[\x00-\x1F\x7F\\\\\/]/u','_',$file->getClientOriginalName()),0,190);
                $attachment=TicketAttachment::create(['tenant_id'=>$locked->tenant_id,'service_ticket_id'=>$locked->id,'uploaded_by'=>$request->user()->id,'original_name'=>$name,'path'=>$path,'mime'=>$file->getMimeType(),'bytes'=>$file->getSize(),'sha256'=>$hash]);
                $locked->worklogs()->create(['user_id'=>$request->user()->id,'action'=>'attachment_added','visibility'=>'internal','content'=>'Thêm ảnh minh chứng: '.$name,'worked_at'=>now()]);
                $audit->record('service_ticket',$locked->id,'add_ticket_attachment',$request->user(),null,['attachment_id'=>$attachment->id,'name'=>$name],$request);
                return $attachment;
            });
        } catch(\Throwable $error) { if($path)Storage::disk('local')->delete($path);throw $error; }
        return response()->json(['data'=>$result],201);
    }
    public function download(Request $request,ServiceTicket $ticket,TicketAttachment $attachment)
    {
        TicketAccess::authorize($request->user(),$ticket);
        abort_unless($attachment->tenant_id===$request->user()->tenant_id && $attachment->service_ticket_id===$ticket->id,404);
        $prefix='ticket-evidence/'.$ticket->tenant_id.'/'.$ticket->id.'/';
        abort_unless(str_starts_with($attachment->path,$prefix) && !str_contains($attachment->path,'..') && Storage::disk('local')->exists($attachment->path),404);
        abort_unless(hash_equals($attachment->sha256,hash_file('sha256',Storage::disk('local')->path($attachment->path))),409,'Ảnh không vượt qua kiểm tra toàn vẹn.');
        return Storage::disk('local')->download($attachment->path,$attachment->original_name,['Content-Type'=>$attachment->mime,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }
}
