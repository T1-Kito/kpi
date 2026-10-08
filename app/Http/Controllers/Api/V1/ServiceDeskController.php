<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ServiceTicket;
use App\Models\WarrantyClaim;
use App\Services\ServiceDesk\ServiceDeskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceDeskController extends Controller
{
    public function __construct(private readonly ServiceDeskService $serviceDesk) {}

    public function tickets(Request $request): JsonResponse
    {
        $query = ServiceTicket::where('tenant_id', $request->user()->tenant_id)->with(['customer:id,code,name', 'assignee:id,name,email'])->latest('id');
        if ($status = $request->query('status')) $query->where('status', $status);
        return $this->paginated($query, $request);
    }
    public function showTicket(Request $request, ServiceTicket $ticket): JsonResponse
    {
        abort_if($ticket->tenant_id !== $request->user()->tenant_id, 404);
        return response()->json(['data' => $ticket->load(['customer:id,code,name,phone,email', 'assignee:id,name,email', 'worklogs.user:id,name,email'])]);
    }
    public function storeTicket(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $ticket = $this->serviceDesk->createTicket($request->user(), $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)], 'serial_number' => ['nullable', 'string', 'max:120'], 'product_name' => ['nullable', 'string', 'max:255'],
            'assignee_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)], 'subject' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'],
            'source' => ['nullable', Rule::in(['manual', 'phone', 'email', 'portal', 'chat'])], 'category' => ['required', Rule::in(['support', 'warranty', 'installation', 'maintenance'])], 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]));
        return response()->json(['data' => $ticket->load(['customer:id,code,name', 'assignee:id,name,email'])], 201);
    }
    public function addWorklog(Request $request, ServiceTicket $ticket): JsonResponse
    {
        abort_if($ticket->tenant_id !== $request->user()->tenant_id, 404);
        $ticket = $this->serviceDesk->addWorklog($ticket, $request->user(), $request->validate(['action' => ['nullable', 'string', 'max:50'], 'visibility' => ['required', Rule::in(['internal', 'public'])], 'content' => ['required', 'string', 'max:3000'], 'minutes_spent' => ['nullable', 'integer', 'min:0', 'max:1440']]));
        return response()->json(['data' => $ticket->load('worklogs.user:id,name,email')]);
    }
    public function updateTicket(Request $request, ServiceTicket $ticket): JsonResponse
    {
        abort_if($ticket->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $ticket = $this->serviceDesk->transitionTicket($ticket, $request->user(), $request->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'pending_customer', 'resolved', 'closed', 'cancelled'])], 'assignee_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)], 'resolution_code' => ['nullable', 'string', 'max:100'], 'resolution_note' => ['nullable', 'string', 'max:2000'], 'satisfaction_score' => ['nullable', 'integer', 'between:1,5']]));
        return response()->json(['data' => $ticket->load(['customer:id,code,name', 'assignee:id,name,email', 'worklogs.user:id,name,email'])]);
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
