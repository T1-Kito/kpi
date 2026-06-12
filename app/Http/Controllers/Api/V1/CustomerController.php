<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use App\Support\BusinessEventPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly CodeGenerator $codes,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = Customer::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('salesOwner:id,name,email');

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $customers = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $customers->items(),
            'meta' => [
                'page' => $customers->currentPage(),
                'page_size' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'sales_owner_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $customer = Customer::create($data + [
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('customers', 'code', 'CUS-', fn ($query) => $query->where('tenant_id', $tenantId)),
            'status' => $data['status'] ?? 'active',
        ]);
        $this->audit->record('customer', $customer->id, 'create_customer', $request->user(), null, $customer->toArray(), $request);
        $this->events->publish($tenantId, 'CustomerCreated', 'Customer', $customer->id, ['code' => $customer->code]);

        return response()->json(['data' => $customer->load('salesOwner:id,name,email')], 201);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        abort_if($customer->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json(['data' => $customer->load('salesOwner:id,name,email')]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        abort_if($customer->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'sales_owner_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $old = $customer->toArray();
        $customer->update($data);
        $this->audit->record('customer', $customer->id, 'update_customer', $request->user(), $old, $customer->fresh()->toArray(), $request);

        return response()->json(['data' => $customer->refresh()->load('salesOwner:id,name,email')]);
    }
}
