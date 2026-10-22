<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class ChatController extends Controller {
    private function conversation(Request $r,int $id) {
        return DB::table('chat_conversations as c')->join('chat_members as m','m.conversation_id','=','c.id')->where('c.id',$id)->where('c.tenant_id',$r->user()->tenant_id)->where('m.user_id',$r->user()->id)->select('c.*')->firstOrFail();
    }
    public function index(Request $r) {
        $uid=$r->user()->id;
        $rows=DB::table('chat_conversations as c')->join('chat_members as m','m.conversation_id','=','c.id')->where('c.tenant_id',$r->user()->tenant_id)->where('m.user_id',$uid)->select('c.*','m.last_read_id')->orderByDesc('c.updated_at')->get();
        foreach($rows as $row){
            $row->members=DB::table('chat_members as m')->join('users as u','u.id','=','m.user_id')->where('m.conversation_id',$row->id)->get(['u.id','u.name','u.is_active']);
            $row->title=$row->name ?: ($row->members->firstWhere('id','!=',$uid)?->name ?? 'Hội thoại');
            $row->last_message=DB::table('chat_messages')->where('conversation_id',$row->id)->latest('id')->value('body');
            $row->unread=DB::table('chat_messages')->where('conversation_id',$row->id)->where('id','>',$row->last_read_id)->where('sender_id','!=',$uid)->count();
        }
        return response()->json(['data'=>$rows]);
    }
    public function users(Request $r){return response()->json(['data'=>DB::table('users')->where('tenant_id',$r->user()->tenant_id)->where('is_active',true)->where('id','!=',$r->user()->id)->orderBy('name')->get(['id','name'])]);}
    public function store(Request $r){
        $data=$r->validate(['name'=>['nullable','string','max:120'],'user_ids'=>['required','array','min:1','max:50'],'user_ids.*'=>['required','integer','distinct',Rule::exists('users','id')->where('tenant_id',$r->user()->tenant_id)->where('is_active',true)]]);
        $ids=collect($data['user_ids'])->push($r->user()->id)->unique()->sort()->values();
        abort_if($ids->count()<2,422,'Chọn ít nhất một đồng nghiệp.');
        abort_if($ids->count()>2 && empty(trim($data['name']??'')),422,'Nhập tên nhóm.');
        $id=DB::transaction(function()use($r,$data,$ids){
            DB::table('tenants')->where('id',$r->user()->tenant_id)->lockForUpdate()->first();
            $key=$ids->count()===2?$ids->implode(':'):null;
            if($key && ($existing=DB::table('chat_conversations')->where('tenant_id',$r->user()->tenant_id)->where('direct_key',$key)->value('id')))return $existing;
            $id=DB::table('chat_conversations')->insertGetId(['tenant_id'=>$r->user()->tenant_id,'name'=>$key?null:trim($data['name']),'direct_key'=>$key,'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            foreach($ids as $uid)DB::table('chat_members')->insert(['conversation_id'=>$id,'user_id'=>$uid]);
            return $id;
        });return response()->json(['data'=>['id'=>(int)$id]],201);
    }
    public function messages(Request $r,int $id){
        $this->conversation($r,$id);
        $data=$r->validate(['before'=>['nullable','integer','min:1']]);
        $rows=DB::table('chat_messages as m')->join('users as u','u.id','=','m.sender_id')->where('m.conversation_id',$id)->when($data['before']??null,fn($q,$before)=>$q->where('m.id','<',$before))->orderByDesc('m.id')->limit(51)->get(['m.*','u.name as sender_name']);
        return response()->json(['data'=>$rows->take(50)->reverse()->values(),'meta'=>['has_more'=>$rows->count()>50]]);
    }
    public function send(Request $r,int $id){
        $this->conversation($r,$id);
        $data=$r->validate(['body'=>['required','string','max:5000'],'client_id'=>['required','uuid']]);
        abort_if(trim($data['body'])==='',422,'Tin nhắn không được để trống.');
        $message=DB::transaction(function()use($r,$id,$data){
            DB::table('chat_conversations')->where('id',$id)->lockForUpdate()->first();
            $existing=DB::table('chat_messages')->where('sender_id',$r->user()->id)->where('client_id',$data['client_id'])->first();
            if($existing){abort_if($existing->conversation_id!=$id,422,'Mã gửi tin đã được sử dụng.');return $existing;}
            $mid=DB::table('chat_messages')->insertGetId(['conversation_id'=>$id,'sender_id'=>$r->user()->id,'body'=>trim($data['body']),'client_id'=>$data['client_id'],'created_at'=>now(),'updated_at'=>now()]);
            DB::table('chat_conversations')->where('id',$id)->update(['updated_at'=>now()]);
            return DB::table('chat_messages')->find($mid);
        });return response()->json(['data'=>$message],201);
    }
    public function read(Request $r,int $id){
        $this->conversation($r,$id);$data=$r->validate(['message_id'=>['required','integer','min:1']]);
        abort_unless(DB::table('chat_messages')->where('conversation_id',$id)->where('id',$data['message_id'])->exists(),422,'Tin nhắn không thuộc hội thoại.');
        DB::table('chat_members')->where('conversation_id',$id)->where('user_id',$r->user()->id)->where('last_read_id','<',$data['message_id'])->update(['last_read_id'=>$data['message_id']]);
        return response()->json(['data'=>['ok'=>true]]);
    }
}
