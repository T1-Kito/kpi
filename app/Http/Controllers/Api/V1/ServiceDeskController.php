<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ServiceTicket;
use App\Models\WarrantyClaim;
use App\Models\User;
use App\Services\ServiceDesk\ServiceDeskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceDeskController extends Controller
{
    public function __construct(private readonly ServiceDeskService $serviceDesk) {}

    public function tickets(Request $request): JsonResponse
    {
        $query = \App\Support\TicketAccess::query($request->user())->with(['customer:id,code,name', 'assignee:id,name,email','coordinator:id,name','department:id,name'])->when(!$request->boolean('compact'),fn($q)=>$q->with('slaEvents'))->latest('id');
        if ($request->filled('document_type') || $request->filled('document_id')) {
            $context=$request->validate(['customer_id'=>['required','integer'],'document_type'=>['required',Rule::in(['quotation','order','delivery','contract'])],'document_id'=>['required','integer','min:1']]);
            \App\Support\FeedbackDocument::query($request->user(),(int)$context['customer_id'],$context['document_type'])->whereKey($context['document_id'])->firstOrFail();
            $query->where('document_type',$context['document_type'])->where('document_id',$context['document_id']);
        }
        $counts=(clone $query)->reorder()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate','status');
        $contextStats=['total'=>(int)$counts->sum(),'open'=>(int)$counts->except(['closed','cancelled'])->sum(),'closed'=>(int)($counts['closed'] ?? 0)];
        $view=$request->validate(['view'=>['nullable',Rule::in(['mine','unassigned','waiting','overdue'])]])['view'] ?? null;
        if($view==='mine')$query->where('assignee_id',$request->user()->id)->whereNotIn('status',['resolved','closed','cancelled']);
        if($view==='unassigned')$query->whereNull('assignee_id')->whereNotIn('status',['resolved','closed','cancelled']);
        if($view==='waiting')$query->where('status','resolved');
        if($view==='overdue')$request->merge(['overdue'=>true]);
        if ($status = $request->query('status')) $query->where('status', $status);
        $extra=$request->validate(['department_id'=>['nullable','integer','min:1'],'assignee_id'=>['nullable','integer','min:1'],'overdue'=>['sometimes','boolean'],'page'=>['sometimes','integer','min:1'],'page_size'=>['sometimes','integer','min:1','max:100']]);
        if(!empty($extra['department_id']))$query->where('department_id',$extra['department_id']);
        if(!empty($extra['assignee_id']))$query->where('assignee_id',$extra['assignee_id']);
        if($request->boolean('overdue'))$query->whereNotIn('status',['resolved','closed','cancelled'])->where('resolution_due_at','<',now())
            ->whereDoesntHave('worklogs',fn($paused)=>$paused->where('action','sla_paused')->whereNotExists(fn($resumed)=>$resumed->selectRaw('1')->from('ticket_worklogs as resumed')->whereColumn('resumed.service_ticket_id','ticket_worklogs.service_ticket_id')->where('resumed.action','sla_resumed')->whereColumn('resumed.id','>','ticket_worklogs.id')));
        $filters = $request->validate(['category'=>['nullable','string','max:50'],'priority'=>['nullable',Rule::in(['low','normal','high','urgent'])],'search'=>['nullable','string','max:120']]);
        foreach (['category','priority'] as $field) if (!empty($filters[$field])) $query->where($field,$filters[$field]);
        if (!empty($filters['search'])) $query->where(fn($q)=>$q->where('code','like','%'.$filters['search'].'%')->orWhere('subject','like','%'.$filters['search'].'%'));
        $query->reorder()->orderByRaw("CASE WHEN status IN ('closed','cancelled') THEN 2 WHEN status='resolved' THEN 1 ELSE 0 END")->orderByRaw("CASE WHEN priority='urgent' THEN 0 WHEN priority='high' THEN 1 WHEN priority='normal' THEN 2 ELSE 3 END")->orderBy('resolution_due_at')->orderByDesc('id');
        $response=$this->paginated($query,$request);$body=$response->getData(true);$body['meta']['stats']=$contextStats;return response()->json($body);
    }
    public function lookups(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json(['data' => [
            'customers' => \App\Support\DataScope::owned(Customer::where('tenant_id', $tenantId), $request->user(), 'sales_owner_id', 'salesOwner')->orderBy('name')->get(['id', 'code', 'name']),
            'groups'=>\App\Support\ServiceRequestWorkflow::groups(),
            'routing'=>$request->user()->tenant->ui_settings['service_routing'] ?? [],
            'departments'=>\App\Models\Department::where('tenant_id',$tenantId)->orderBy('name')->get(['id','name']),
            'users' => User::where('tenant_id', $tenantId)->where('is_active', true)->orderBy('name')->get()->filter(fn ($user) => $user->hasPermission('service.ticket.manage'))->map(fn ($user) => $user->only(['id','name','department_id']))->values(),
        ]]);
    }
    public function saveRouting(Request $request): JsonResponse
    {
        $data=$request->validate(['routing'=>['present','array','max:9'],'routing.*'=>['array:department_id,assignee_id,coordinator_id,supervisor_id,response_minutes,resolution_minutes'],'routing.*.department_id'=>['nullable','integer','min:1'],'routing.*.assignee_id'=>['nullable','integer','min:1'],'routing.*.coordinator_id'=>['nullable','integer','min:1'],'routing.*.supervisor_id'=>['nullable','integer','min:1'],'routing.*.response_minutes'=>['nullable','integer','min:1','max:525600'],'routing.*.resolution_minutes'=>['nullable','integer','min:1','max:525600']]);
        abort_if(array_diff(array_keys($data['routing']),array_keys(\App\Support\ServiceRequestWorkflow::groups())),422,'Nhóm yêu cầu không hợp lệ.');
        foreach($data['routing'] as $rule){\App\Support\ServiceRequestWorkflow::validatePeople($request->user(),$rule);abort_if(!empty($rule['response_minutes'])&&!empty($rule['resolution_minutes'])&&$rule['response_minutes']>$rule['resolution_minutes'],422,'Hạn phản hồi không được dài hơn hạn xử lý.');}
        \Illuminate\Support\Facades\DB::transaction(function()use($request,$data){$tenant=\App\Models\Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();$settings=$tenant->ui_settings ?? [];$before=$settings['service_routing'] ?? [];$settings['service_routing']=$data['routing'];$tenant->update(['ui_settings'=>$settings]);app(\App\Support\AuditLogger::class)->record('tenant',$tenant->id,'configure_service_routing',$request->user(),$before,$data['routing']);});
        return response()->json(['data'=>$data['routing']]);
    }
    public function documents(Request $request): JsonResponse
    {
        $data=$request->validate(['customer_id'=>['nullable','integer'],'type'=>['required',Rule::in(['quotation','order','delivery','contract'])],'search'=>['nullable','string','max:120'],'page'=>['sometimes','integer','min:1'],'document_id'=>['nullable','integer','min:1']]);
        $query=\App\Support\FeedbackDocument::query($request->user(),!empty($data['customer_id'])?(int)$data['customer_id']:null,$data['type']);
        $relation=$data['type']==='delivery'?'salesOrder.customer':'customer';
        $rows=$query->with($relation)->when(!empty($data['document_id']),fn($q)=>$q->whereKey($data['document_id']))
            ->when(!empty($data['search']),fn($q)=>$q->where(fn($q)=>$q->where('code','like','%'.$data['search'].'%')->orWhereHas($relation,fn($c)=>$c->where('name','like','%'.$data['search'].'%')->orWhere('phone','like','%'.$data['search'].'%'))))
            ->latest('id')->paginate(30);
        return response()->json(['data'=>collect($rows->items())->map(function($row)use($data){$customer=$data['type']==='delivery'?$row->salesOrder?->customer:$row->customer;return ['id'=>$row->id,'code'=>$row->code,'customer_id'=>$customer?->id,'customer_name'=>$customer?->name];}),'meta'=>['page'=>$rows->currentPage(),'last_page'=>$rows->lastPage()]]);
    }
    public function showTicket(Request $request, ServiceTicket $ticket): JsonResponse
    {
        \App\Support\TicketAccess::authorize($request->user(),$ticket);
        $ticket->load('attachments');
        abort_if($ticket->tenant_id !== $request->user()->tenant_id, 404);
        return response()->json(['data' => $ticket->load(['customer:id,code,name,phone,email', 'assignee:id,name,email', 'worklogs.user:id,name,email','coordinator:id,name','department:id,name']),'meta'=>['groups'=>\App\Support\ServiceRequestWorkflow::groups()]]);
    }
    public function storeTicket(Request $request): JsonResponse
    {
        $request->validate(['customer_id'=>['nullable','integer']]);
        if($request->filled('customer_id')) abort_unless(\App\Support\DataScope::owned(Customer::where('tenant_id',$request->user()->tenant_id)->whereKey($request->input('customer_id')), $request->user(), 'sales_owner_id', 'salesOwner')->exists(),404);
        $link=$request->validate(['document_type'=>['nullable','required_with:document_id',Rule::in(['quotation','order','delivery','contract'])],'document_id'=>['nullable','required_with:document_type','integer','min:1']]);
        if (!empty($link['document_type'])) {
            $document=\App\Support\FeedbackDocument::query($request->user(),$request->filled('customer_id')?(int)$request->input('customer_id'):null,$link['document_type'])->whereKey($link['document_id'])->firstOrFail();
            $request->merge(['related_document'=>$document->code,'customer_id'=>$link['document_type']==='delivery'?$document->salesOrder->customer_id:$document->customer_id]);
        }
        $tenantId = $request->user()->tenant_id;
        $ticket = $this->serviceDesk->createTicket($request->user(), $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)], 'serial_number' => ['nullable', 'string', 'max:120'], 'product_name' => ['nullable', 'string', 'max:255'],
            'assignee_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)], 'subject' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'],
            'resolution_due_at' => ['nullable', 'date', 'after:now'],
            'request_details'=>['nullable','array','max:30'],'department_id'=>['nullable','integer','min:1'],'coordinator_id'=>['nullable','integer','min:1'],
            'related_document' => ['nullable','string','max:120'],
            'document_type'=>['nullable',Rule::in(['quotation','order','delivery','contract'])], 'document_id'=>['nullable','integer','min:1'],
            'source' => ['nullable', Rule::in(['manual', 'phone', 'email', 'portal', 'chat'])], 'category' => ['required', Rule::in(['support', 'warranty', 'installation', 'maintenance','quotation','order','delivery','product','complaint','ticket_advice','group_sales','resort','online_payment','onsite','lost_property','suggestion','aftercare'])], 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]));
        return response()->json(['data' => $ticket->load(['customer:id,code,name', 'assignee:id,name,email'])], 201);
    }
    public function addWorklog(Request $request, ServiceTicket $ticket): JsonResponse
    {
        \App\Support\TicketAccess::authorize($request->user(),$ticket);
        abort_if($ticket->tenant_id !== $request->user()->tenant_id, 404);
        $ticket = $this->serviceDesk->addWorklog($ticket, $request->user(), $request->validate(['action' => ['nullable', 'string', 'max:50'], 'visibility' => ['required', Rule::in(['internal', 'public'])], 'content' => ['required', 'string', 'max:3000'], 'minutes_spent' => ['nullable', 'integer', 'min:0', 'max:1440']]));
        return response()->json(['data' => $ticket->load('worklogs.user:id,name,email','coordinator:id,name','department:id,name')]);
    }
    public function updateTicket(Request $request, ServiceTicket $ticket): JsonResponse
    {
        \App\Support\TicketAccess::authorize($request->user(),$ticket);
        $confirmation=$request->validate(['customer_confirmation'=>['nullable',Rule::in(['accepted','not_contacted','rejected'])]]);
        if ($request->input('status') === 'closed') $request->validate(['customer_confirmation'=>['required',Rule::in(['accepted'])],'resolution_note'=>['required','string','max:2000']]);
        abort_if($ticket->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $ticket = $this->serviceDesk->transitionTicket($ticket, $request->user(), $request->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'pending_customer', 'resolved', 'closed', 'cancelled'])], 'assignee_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)], 'resolution_code' => ['nullable', 'string', 'max:100'], 'resolution_note' => ['nullable', 'string', 'max:2000'], 'satisfaction_score' => ['nullable', 'integer', 'between:1,5'],'request_details'=>['nullable','array','max:30'],'department_id'=>['nullable','integer','min:1'],'coordinator_id'=>['nullable','integer','min:1']])+$confirmation);
        return response()->json(['data' => $ticket->load(['customer:id,code,name', 'assignee:id,name,email', 'worklogs.user:id,name,email','coordinator:id,name','department:id,name'])]);
    }
    public function acceptTicket(Request $request, ServiceTicket $ticket): JsonResponse
    {
        \App\Support\TicketAccess::authorize($request->user(),$ticket);
        abort_unless((int)$ticket->assignee_id===(int)$request->user()->id,403,'Chỉ người được giao mới xác nhận tiếp nhận.');
        abort_unless($ticket->status==='open',422,'Yêu cầu đã được tiếp nhận hoặc đã chuyển trạng thái.');
        return response()->json(['data'=>$this->serviceDesk->transitionTicket($ticket,$request->user(),['status'=>'in_progress','resolution_note'=>'Người được giao đã xác nhận tiếp nhận xử lý.'])]);
    }
    public function clock(Request $request, ServiceTicket $ticket): JsonResponse
    {
        $data=$request->validate(['pause'=>['required','boolean'],'reason'=>['required','string','max:2000']]);
        $ticket=\App\Support\ServiceTicketSla::pause($ticket,$request->user(),(bool)$data['pause'],$data['reason']);
        return response()->json(['data'=>$ticket]);
    }
    public function warranties(Request $request): JsonResponse
    {
        $query = WarrantyClaim::where('tenant_id', $request->user()->tenant_id)->with('customer:id,code,name')->latest('id');
        $stats=['total'=>(clone $query)->count(),'active'=>(clone $query)->where('warranty_status','in_warranty')->count(),'expired'=>(clone $query)->where('warranty_status','out_of_warranty')->count(),'today'=>(clone $query)->where('created_at','>=',now('Asia/Ho_Chi_Minh')->startOfDay()->utc())->count()];
        $stats=['total'=>(clone $query)->count(),'active'=>(clone $query)->where('warranty_status','in_warranty')->count(),'expired'=>(clone $query)->where('warranty_status','out_of_warranty')->count(),'today'=>(clone $query)->whereDate('created_at',now()->toDateString())->count()];
        if ($serial = $request->query('serial')) $query->where('serial_number', 'like', "%{$serial}%");
        if ($product = $request->query('product')) $query->where('product_name', 'like', "%{$product}%");
        if ($customer = $request->query('customer')) $query->where(fn ($q) => $q->where('customer_name', 'like', "%{$customer}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$customer}%")));
        if ($purchaseDate = $request->query('purchase_date')) $query->whereDate('purchase_date', $purchaseDate);
        $response=$this->paginated($query,$request);$body=$response->getData(true);$body['meta']['stats']=$stats;return response()->json($body);
    }
    public function lookupWarrantyCustomer(Request $request, string $taxCode): JsonResponse
    {
        $customer = Customer::where('tenant_id', $request->user()->tenant_id)->where('tax_code', $taxCode)->first();
        return response()->json(['data' => $customer ? $customer->only(['id', 'code', 'name', 'contact_name', 'phone', 'email', 'address', 'tax_code']) : null]);
    }
    public function storeWarranty(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $records = $this->serviceDesk->registerWarranties($request->user(), $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)], 'serial_numbers' => ['required', 'array', 'min:1', 'max:100'], 'serial_numbers.*' => ['required', 'string', 'max:120'],
            'product_name' => ['required', 'string', 'max:255'], 'tax_code' => ['nullable', 'string', 'max:50'], 'customer_name' => ['required', 'string', 'max:255'], 'customer_phone' => ['nullable', 'string', 'max:50', 'phone_number'], 'customer_email' => ['nullable', 'email', 'max:255'], 'customer_address' => ['nullable', 'string', 'max:1000'],
            'purchase_date' => ['nullable', 'date'], 'warranty_start_at' => ['required', 'date'], 'warranty_months' => ['required', 'integer', 'min:1', 'max:120'],
        ]));
        $created = WarrantyClaim::with('customer:id,code,name')->whereKey(collect($records)->pluck('id'))->orderBy('id')->get();
        return response()->json(['data' => $created, 'meta' => ['created' => count($records)]], 201);
    }
    private function paginated($query, Request $request): JsonResponse
    {
        $rows = $query->paginate(min((int) $request->query('page_size', 20), 100));
        if($request->boolean('compact'))$rows->getCollection()->each(fn($row)=>$row->setAppends([]));
        return response()->json(['data' => $rows->items(), 'meta' => ['page' => $rows->currentPage(), 'page_size' => $rows->perPage(), 'total' => $rows->total()]]);
    }
}
