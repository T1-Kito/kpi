<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Customer,Task};
use App\Support\{DataScope,TaskService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerCareController extends Controller
{
    public function board(Request $request)
    {
        $data=$request->validate(['bucket'=>['sometimes',Rule::in(['overdue','today','upcoming','unscheduled'])],'page'=>['sometimes','integer','min:1']]);
        $actor=$request->user();$bucket=$data['bucket'] ?? 'today';
        $customers=DataScope::owned(Customer::where('tenant_id',$actor->tenant_id)->whereNull('merged_into_id')->where('status','active'),$actor,'sales_owner_id','salesOwner');
        $open=Task::where('tenant_id',$actor->tenant_id)->where('source_type','Customer')->where('task_type','customer_follow_up')->whereIn('status',['new','in_progress','overdue']);
        $start=now()->setTimezone('Asia/Ho_Chi_Minh')->startOfDay()->utc();$end=$start->copy()->addDay();
        $counts=[];
        foreach(['overdue','today','upcoming'] as $key) {
            $q=(clone $open)->whereIn('source_id',(clone $customers)->select('id'));
            if($key==='overdue')$q->where('due_at','<',now());
            if($key==='today')$q->where('due_at','>=',$start)->where('due_at','<',$end);
            if($key==='upcoming')$q->where('due_at','>=',$end);
            $counts[$key]=$q->count();
            if($bucket===$key)$selected=$q;
        }
        $missing=(clone $customers)->whereNotIn('id',(clone $open)->select('source_id'));
        $counts['unscheduled']=$missing->count();
        if($bucket==='unscheduled')$rows=$missing->with('salesOwner:id,name')->orderBy('id')->paginate(20);
        else {
            $rows=$selected->with('assignee:id,name')->orderBy('due_at')->orderBy('id')->paginate(20);
            $names=(clone $customers)->whereIn('id',collect($rows->items())->pluck('source_id'))->get(['id','code','name'])->keyBy('id');
            foreach($rows as $task)$task->setAttribute('customer',$names->get($task->source_id));
        }
        return response()->json(['data'=>$rows->items(),'counts'=>$counts,'meta'=>['page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }
    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        abort_unless($customer->tenant_id === $request->user()->tenant_id && !$customer->merged_into_id && DataScope::owned(Customer::whereKey($customer->id),$request->user(),'sales_owner_id','salesOwner')->exists(),404);
    }

    private function query(Customer $customer)
    {
        return Task::where('tenant_id',$customer->tenant_id)->where('source_type','Customer')->where('source_id',$customer->id)->where('task_type','customer_follow_up');
    }

    public function index(Request $request, Customer $customer)
    {
        $this->authorizeCustomer($request,$customer);
        $query=$this->query($customer);
        $open=(clone $query)->whereIn('status',['new','in_progress','overdue']);
        $history=(clone $query)->where('status','completed')->with(['history'=>fn($q)=>$q->where('to_status','completed')->latest('id')])->get();
        $last=$history->flatMap(fn($task)=>$task->history)->sortByDesc('created_at')->first();
        $request->validate(['page'=>['sometimes','integer','min:1']]);
        $page=(clone $query)->with('assignee:id,name')->latest('id')->paginate(30);
        return response()->json(['data'=>[
            'owner'=>$customer->salesOwner()->first(['id','name','is_active']),
            'last_contact_at'=>$last?->created_at,
            'last_result'=>$last?->reason,
            'overdue_count'=>(clone $open)->where('due_at','<',now())->count(),
            'next_task'=>(clone $open)->whereNotNull('due_at')->orderBy('due_at')->first(['id','code','title','due_at','status']),
            'tasks'=>$page->items(),
        ],'meta'=>['page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'total'=>$page->total()]]);
    }

    public function store(Request $request, Customer $customer, TaskService $tasks)
    {
        $this->authorizeCustomer($request,$customer);
        $data=$request->validate(['title'=>['required','string','max:255'],'due_at'=>['required','date','after:now']]);
        return DB::transaction(function() use($customer,$request,$data,$tasks) {
            $customer=Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $this->authorizeCustomer($request,$customer);
            abort_unless($customer->status==='active' && $customer->salesOwner && $customer->salesOwner->is_active && $customer->salesOwner->hasPermission('task.view'),422,'Cần phân công khách hàng cho nhân viên đang hoạt động và có quyền công việc.');
            $task=$tasks->create($request->user(),['title'=>$data['title'],'due_at'=>$data['due_at'],'module'=>'sales','task_type'=>'customer_follow_up','source_type'=>'Customer','source_id'=>$customer->id,'assignee_id'=>$customer->sales_owner_id,'department_id'=>$customer->salesOwner->department_id],false);
            return response()->json(['data'=>$task],201);
        });
    }

    public function complete(Request $request, Customer $customer, Task $task, TaskService $tasks)
    {
        $this->authorizeCustomer($request,$customer);
        abort_unless($this->query($customer)->whereKey($task->id)->exists(),404);
        $data=$request->validate(['outcome'=>['required',Rule::in(['contacted','no_answer','meeting','information_sent'])],'note'=>['required','string','max:400'],'next_title'=>['nullable','required_with:next_due_at','required_if:outcome,no_answer','string','max:255'],'next_due_at'=>['nullable','required_with:next_title','required_if:outcome,no_answer','date','after:now']]);
        return DB::transaction(function() use($request,$customer,$task,$tasks,$data) {
            $customer=Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $this->authorizeCustomer($request,$customer);
            $task=Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($task->status,['new','in_progress','overdue']),409,'Công việc đã kết thúc.');
            $labels=['contacted'=>'Đã liên hệ','no_answer'=>'Không nghe máy','meeting'=>'Đã gặp','information_sent'=>'Đã gửi thông tin'];
            $next=null;
            if(!empty($data['next_due_at'])) {
                abort_unless($customer->status==='active' && $customer->salesOwner && $customer->salesOwner->is_active && $customer->salesOwner->hasPermission('task.view'),422,'Cần người phụ trách đang hoạt động và có quyền công việc để hẹn tiếp.');
                $next=$tasks->create($request->user(),['title'=>$data['next_title'],'due_at'=>$data['next_due_at'],'module'=>'sales','task_type'=>'customer_follow_up','source_type'=>'Customer','source_id'=>$customer->id,'assignee_id'=>$customer->sales_owner_id,'department_id'=>$customer->salesOwner->department_id],false);
            }
            return response()->json(['data'=>$tasks->changeStatus($task,$request->user(),'completed',$labels[$data['outcome']].': '.$data['note']),'next_task'=>$next]);
        });
    }
}
