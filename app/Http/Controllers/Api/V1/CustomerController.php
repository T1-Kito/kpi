<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use App\Support\BusinessEventPublisher;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
        DataScope::owned($query, $request->user(), 'sales_owner_id', 'salesOwner');

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('tax_code', 'like', "%{$search}%")
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
            'tax_code' => ['nullable', 'string', 'max:30', Rule::unique('customers')->where('tenant_id', $tenantId)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'sales_owner_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $data['tax_code'] = $this->normalizeTaxCode($data['tax_code'] ?? null);

        $customer = Customer::create($data + [
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('customers', 'code', 'CUS-', fn ($query) => $query->where('tenant_id', $tenantId)),
            'status' => $data['status'] ?? 'active',
        ]);
        $this->audit->record('customer', $customer->id, 'create_customer', $request->user(), null, $customer->toArray(), $request);
        $this->events->publish($tenantId, 'CustomerCreated', 'Customer', $customer->id, ['code' => $customer->code]);

        return response()->json(['data' => $customer->load('salesOwner:id,name,email')], 201);
    }

    public function quickStore(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $taxCode = $this->normalizeTaxCode($data['tax_code'] ?? null);
        $phone = preg_replace('/\s+/', '', trim($data['phone']));
        if ($taxCode) {
            $existingByTax = Customer::query()
                ->where('tenant_id', $tenantId)
                ->where('tax_code', $taxCode)
                ->first();

            if ($existingByTax) {
                return response()->json([
                    'message' => 'Mã số thuế đã thuộc một khách hàng trong hệ thống.',
                    'data' => $existingByTax->load('salesOwner:id,name,email'),
                    'meta' => ['duplicate_tax_code' => true, 'duplicate_phone' => false],
                ]);
            }
        }

        $existing = Customer::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw("REPLACE(phone, ' ', '') = ?", [$phone])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Số điện thoại đã thuộc một khách hàng trong hệ thống.',
                'data' => $existing->load('salesOwner:id,name,email'),
                'meta' => ['duplicate_tax_code' => false, 'duplicate_phone' => true],
            ]);
        }

        $customer = Customer::create([
            ...$data,
            'tax_code' => $taxCode,
            'phone' => $phone,
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('customers', 'code', 'CUS-', fn ($query) => $query->where('tenant_id', $tenantId)),
            'sales_owner_id' => $request->user()->id,
            'status' => 'active',
        ]);

        $this->audit->record('customer', $customer->id, 'create_customer_quick', $request->user(), null, $customer->toArray(), $request);
        $this->events->publish($tenantId, 'CustomerCreated', 'Customer', $customer->id, [
            'code' => $customer->code,
            'source' => 'quotation_quick_create',
        ]);

        return response()->json([
            'data' => $customer->load('salesOwner:id,name,email'),
            'meta' => ['duplicate_tax_code' => false, 'duplicate_phone' => false],
        ], 201);
    }

    public function lookupTaxCode(Request $request, string $taxCode): JsonResponse
    {
        $taxCode = $this->normalizeTaxCode($taxCode);
        abort_if(! $taxCode, 422, 'Mã số thuế không hợp lệ.');

        $customer = Customer::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('tax_code', $taxCode)
            ->first();

        if ($customer) {
            return response()->json([
                'data' => [
                    'tax_code' => $customer->tax_code,
                    'name' => $customer->name,
                    'billing_address' => $customer->billing_address,
                    'address' => $customer->address,
                    'contact_name' => $customer->contact_name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                    'customer_id' => $customer->id,
                ],
                'meta' => ['source' => 'customer_database'],
            ]);
        }

        $url = config('services.tax_lookup.url');
        if (! $url) {
            return response()->json([
                'data' => null,
                'message' => 'Chưa tìm thấy mã số thuế trong dữ liệu hiện có.',
                'meta' => ['source' => 'not_configured'],
            ], 404);
        }

        $client = Http::acceptJson()->timeout(8);
        if ($key = config('services.tax_lookup.key')) {
            $client = $client->withToken($key);
        }

        try {
            $response = $client->get(str_replace('{tax_code}', rawurlencode($taxCode), $url));
        } catch (\Throwable) {
            return response()->json(['data' => null, 'message' => 'Dịch vụ tra cứu mã số thuế chưa phản hồi.'], 503);
        }
        if (! $response->successful()) {
            return response()->json(['data' => null, 'message' => 'Không tra cứu được mã số thuế.'], 404);
        }

        $payload = $response->json('data') ?? $response->json();
        return response()->json([
            'data' => [
                'tax_code' => $payload['tax_code'] ?? $payload['taxCode'] ?? $taxCode,
                'name' => $payload['name'] ?? $payload['company_name'] ?? $payload['companyName'] ?? null,
                'billing_address' => $payload['billing_address'] ?? $payload['billingAddress'] ?? $payload['address'] ?? null,
                'address' => $payload['address'] ?? null,
                'contact_name' => $payload['representative'] ?? $payload['legal_representative'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'email' => $payload['email'] ?? null,
            ],
            'meta' => ['source' => 'external'],
        ]);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        abort_if($customer->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Customer::whereKey($customer->id), $request->user(), 'sales_owner_id', 'salesOwner')->exists(), 404);

        return response()->json(['data' => $customer->load('salesOwner:id,name,email')]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        abort_if($customer->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_code' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('customers')->where('tenant_id', $tenantId)->ignore($customer->id),
            ],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'sales_owner_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $data['tax_code'] = $this->normalizeTaxCode($data['tax_code'] ?? null);

        $old = $customer->toArray();
        $customer->update($data);
        $this->audit->record('customer', $customer->id, 'update_customer', $request->user(), $old, $customer->fresh()->toArray(), $request);

        return response()->json(['data' => $customer->refresh()->load('salesOwner:id,name,email')]);
    }

    private function normalizeTaxCode(?string $taxCode): ?string
    {
        $value = preg_replace('/[^0-9-]/', '', trim((string) $taxCode));

        return $value !== '' ? $value : null;
    }
}
