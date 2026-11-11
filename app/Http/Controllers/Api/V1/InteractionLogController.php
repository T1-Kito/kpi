<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
final class InteractionLogController extends Controller
{
    public function store(Request $request) {
        $data=$request->validate(['events'=>['required','array','min:1','max:20'],
            'events.*.kind'=>['required',Rule::in(['page','click','request_success','request_error'])],
            'events.*.path'=>['required','string','max:160','regex:~^/[a-z0-9/-]*$~D'],
            'events.*.control'=>['nullable','string','max:80','regex:/^[a-z0-9_-]+$/D'],'events.*.at'=>['nullable','date']]);
        $rows=[];$now=now();
        foreach($data['events'] as $event) {
            $observed=isset($event['at'])?\Carbon\CarbonImmutable::parse($event['at'])->utc():$now->toImmutable();
            if(abs($observed->getTimestamp()-$now->getTimestamp())>120)$observed=$now->toImmutable();
            // No form values, URL query, button text or client-supplied identity is accepted.
            $rows[]=['tenant_id'=>$request->user()->tenant_id,'user_id'=>$request->user()->id,'entity_type'=>'ui_activity','entity_id'=>null,
                'action'=>'ui_'.$event['kind'],'new_value'=>json_encode(['path'=>$event['path'],'control'=>$event['control'] ?? null,'origin'=>'browser','observed_at'=>$observed->toIso8601String()],JSON_UNESCAPED_UNICODE),
                'ip_address'=>$request->ip(),'user_agent'=>mb_substr($request->userAgent() ?? '',0,500),'created_at'=>$now,'updated_at'=>$now];
        }
        AuditLog::insert($rows);
        return response()->json(['recorded'=>count($rows)],201);
    }
}
