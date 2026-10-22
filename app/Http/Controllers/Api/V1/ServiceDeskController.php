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
        $query = \App\Support\TicketAccess::query($request->user())->with(['customer:id,code,name', 'assignee:id,name,email','slaEvents'])->latest('id');
        if ($status = $request->query('status')) $query->where('status', $status);
        $extra=$request->validate(['assignee_id'=>['nullable','integer','min:1'],'overdue'=>['sometimes','boolean'],'page'=>['sometimes','integer','min:1'],'page_size'=>['sometimes','integer','min:1','max:100']]);
        if(!empty($extra['assignee_id']))$query->where('assignee_id',$extra['assignee_id']);
        if($request->boolean('overdue'))$query->whereNotIn('status',['resolved','closed','cancelled'])->where('resolution_due_at','<',now())
            ->whereDoesntHave('worklogs',fn($paused)=>$paused->where('action','sla_paused')->whereNotExists(fn($resumed)=>$resumed->selectRaw('1')->from('ticket_worklogs as resumed')->whereColumn('resumed.service_ticket_id','ticket_worklogs.service_ticket_id')->where('resumed.action','sla_resumed')->whereColumn('resumed.id','>','ticket_worklogs.id')));
        $filters = $request->validate(['category'=>['nullable','string','max:50'],'priority'=>['nullable',Rule::in(['low','normal','high','urgent'])],'search'=>['nullable','string','max:120']]);
        foreach (['category','priority'] as $field) if (!empty($filters[$field])) $query->where($field,$filters[$field]);
        if (!empty($filters['search'])) $query->where(fn($q)=>$q->where('code','like','%'.$filters['search'].'%')->orWhere('subject','like','%'.$filters['search'].'%'));
        return $this->paginated($query, $request);
    }
    public function lookups(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json(['data' => [
            'customers' => \App\Support\DataScope::owned(Customer::where('tenant_id', $tenantId), $request->user(), 'sales_owner_id', 'salesOwner')->orderBy('name')->get(['id', 'code', 'name']),
            'users' => User::where('tenant_id', $tenantId)->where('is_active', true)->orderBy('name')->get()->filter(fn ($user) => $user->hasPermission('service.ticket.view'))->map(fn ($user) => $user->only(['id','name']))->values(),
        ]]);
    }
    public function documents(Request $request): JsonResponse
    {
        $data=$request->validate(['customer_id'=>['required','integer'],'type'=>['required',Rule::in(['quotation','order','delivery'])],'page'=>['sometimes','integer','min:1']]);
        $rows=\App\Support\FeedbackDocument::query($request->user(),(int)$data['customer_id'],$data['type'])->latest('id')->paginate(30,['id','code']);
        return response()->json(['data'=>$rows->items(),'meta'=>['page'=>$rows->currentPage(),'last_page'=>$rows->lastPage()]]);
    }
    public function showTicket(Request $request, ServiceTicket $ticket): JsonResponse
    {
        \App\Support\TicketAccess::authorize($request->user(),$ticket);
        $ticket->load('attachments');
        abort_if($ticket->tenant_id !== $request->user()->tenant_id, 404);
        return response()->json(['data' => $ticket->load(['customer:id,code,name,phone,email', 'assignee:id,name,email', 'worklogs.user:id,name,email'])]);
    }
    public function storeTicket(Request $request): JsonResponse
    {
        $request->validate(['customer_id'=>['required','integer']]);
        abort_unless(\App\Support\DataScope::owned(Customer::where('tenant_id',$request->user()->tenant_id)->whereKey($request->input('customer_id')), $request->user(), 'sales_owner_id', 'salesOwner')->exists(),404);
        $link=$request->validate(['document_type'=>['nullable','required_with:document_id',Rule::in(['quotation','order','delivery'])],'document_id'=>['nullable','required_with:document_type','integer','min:1']]);
        if (!empty($link['document_type'])) {
            $document=\App\Support\FeedbackDocument::query($request->user(),(int)$request->input('customer_id'),$link['document_type'])->whereKey($link['document_id'])->firstOrFail();
            $request->merge(['related_document'=>$document->code]);
        }
        $tenantId = $request->user()->tenant_id;
        $ticket = $this->serviceDesk->createTicket($request->user(), $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)], 'serial_number' => ['nullable', 'string', 'max:120'], 'product_name' => ['nullable', 'string', 'max:255'],
            'assignee_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)], 'subject' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'],
            'resolution_due_at' => ['nullable', 'date', 'after:now'],
            'related_document' => ['nullable','string','max:120'],
            'document_type'=>['nullable',Rule::in(['quotation','order','delivery'])], 'document_id'=>['nullable','integer','min:1'],
            'source' => ['nullable', Rule::in(['manual', 'phone', 'email', 'portal', 'chat'])], 'category' => ['required', Rule::in(['support', 'warranty', 'installation', 'maintenance','quotation','order','delivery','product','complaint'])], 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]));
        return response()->json(['data' => $ticket->load(['customer:id,code,name', 'assignee:id,name,email'])], 201);
    }
    public function addWorklog(Request $request, ServiceTicket $ticket): JsonResponse
    {
        \App\Support\TicketAccess::authorize($request->user(),$ticket);
        abort_if($ticket->tenant_id !== $request->user()->tenant_id, 404);
        $ticket = $this->serviceDesk->addWorklog($ticket, $request->user(), $request->validate(['action' => ['nullable', 'string', 'max:50'], 'visibility' => ['required', Rule::in(['internal', 'public'])], 'content' => ['required', 'string', 'max:3000'], 'minutes_spent' => ['nullable', 'integer', 'min:0', 'max:1440']]));
        return response()->json(['data' => $ticket->load('worklogs.user:id,name,email')]);
    }
    public function updateTicket(Request $request, ServiceTicket $ticket): JsonResponse
    {
        \App\Support\TicketAccess::authorize($request->user(),$ticket);
        $confirmation=$request->validate(['customer_confirmation'=>['nullable',Rule::in(['accepted','not_contacted','rejected'])]]);
        if ($request->input('status') === 'closed') $request->validate(['customer_confirmation'=>['required',Rule::in(['accepted'])],'resolution_note'=>['required','string','max:2000']]);
        abort_if($ticket->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $ticket = $this->serviceDesk->transitionTicket($ticket, $request->user(), $request->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'pending_customer', 'resolved', 'closed', 'cancelled'])], 'assignee_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)], 'resolution_code' => ['nullable', 'string', 'max:100'], 'resolution_note' => ['nullable', 'string', 'max:2000'], 'satisfaction_score' => ['nullable', 'integer', 'between:1,5']])+$confirmation);
        return response()->json(['data' => $ticket->load(['customer:id,code,name', 'assignee:id,name,email', 'worklogs.user:id,name,email'])]);
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
        if ($serial = $request->query('serial')) $query->where('serial_number', 'like', "%{$serial}%");
        if ($product = $request->query('product')) $query->where('product_name', 'like', "%{$product}%");
        if ($customer = $request->query('customer')) $query->where(fn ($q) => $q->where('customer_name', 'like', "%{$customer}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$customer}%")));
        if ($purchaseDate = $request->query('purchase_date')) $query->whereDate('purchase_date', $purchaseDate);
        return $this->paginated($query, $request);
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
            'product_name' => ['required', 'string', 'max:255'], 'tax_code' => ['nullable', 'string', 'max:50'], 'customer_name' => ['required', 'string', 'max:255'], 'customer_phone' => ['nullable', 'string', 'max:50'], 'customer_email' => ['nullable', 'email', 'max:255'], 'customer_address' => ['nullable', 'string', 'max:1000'],
            'purchase_date' => ['nullable', 'date'], 'warranty_start_at' => ['required', 'date'], 'warranty_months' => ['required', 'integer', 'min:1', 'max:120'],
        ]));
        $created = WarrantyClaim::with('customer:id,code,name')->whereKey(collect($records)->pluck('id'))->orderBy('id')->get();
        return response()->json(['data' => $created, 'meta' => ['created' => count($records)]], 201);
    }
    private function paginated($query, Request $request): JsonResponse
    {
        $rows = $query->paginate(min((int) $request->query('page_size', 20), 100));
        return response()->json(['data' => $rows->items(), 'meta' => ['page' => $rows->currentPage(), 'page_size' => $rows->perPage(), 'total' => $rows->total()]]);
    }
}
