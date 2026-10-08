<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerPayment;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\ServiceTicket;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use App\Support\BusinessEventPublisher;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly CodeGenerator $codes,
    ) {
    }

    public function contacts(Request $request): JsonResponse
    {
        $customers = Customer::where('tenant_id', $request->user()->tenant_id)->whereNull('merged_into_id');
        DataScope::owned($customers, $request->user(), 'sales_owner_id', 'salesOwner');
        $query = CustomerContact::whereIn('customer_id', $customers->select('customers.id'))->with('customer:id,code,name');
        if ($search = $request->query('q')) $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")));
        $rows = $query->latest('id')->paginate(20);
        return response()->json(['data' => $rows->items(), 'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['customer_type' => ['nullable', Rule::in(['person', 'organization'])]]);
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = Customer::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereNull('merged_into_id')
            ->with(['salesOwner:id,name,email', 'contacts']);
        DataScope::owned($query, $request->user(), 'sales_owner_id', 'salesOwner');

        if (! empty($filters['customer_type'])) {
            $query->where('customer_type', $filters['customer_type']);
        }

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
            'customer_type' => ['nullable', Rule::in(['person', 'organization'])],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'identity_number' => ['nullable', 'regex:/^[0-9]{12}$/', Rule::unique('customers', 'identity_number')->where('tenant_id', $tenantId)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'legal_representative' => ['nullable', 'string', 'max:255'],
            'representative_position' => ['nullable', 'string', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'sales_owner_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $data['tax_code'] = $this->normalizeTaxCode($data['tax_code'] ?? null);
        if ($data['tax_code'] && Customer::where('tenant_id', $tenantId)->where('tax_code', $data['tax_code'])->exists()) {
            return response()->json(['message' => 'Mã số thuế đã thuộc một khách hàng trong hệ thống.'], 409);
        }

        $customer = Customer::create($data + [
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('customers', 'code', 'CUS-', fn ($query) => $query->where('tenant_id', $tenantId)),
            'status' => $data['status'] ?? 'active',
        ]);
        $this->syncPrimaryContact($customer, $data);
        $this->audit->record('customer', $customer->id, 'create_customer', $request->user(), null, $customer->toArray(), $request);
        $this->events->publish($tenantId, 'CustomerCreated', 'Customer', $customer->id, ['code' => $customer->code]);

        return response()->json(['data' => $customer->load('salesOwner:id,name,email')], 201);
    }

    public function quickStore(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'customer_type' => ['nullable', Rule::in(['person', 'organization'])],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'legal_representative' => ['nullable', 'string', 'max:255'],
            'representative_position' => ['nullable', 'string', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $taxCode = $this->normalizeTaxCode($data['tax_code'] ?? null);
        $phone = preg_replace('/\s+/', '', trim($data['phone']));
        if ($taxCode) {
            $existingByTax = Customer::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('merged_into_id')
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
            ->whereNull('merged_into_id')
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

        $this->syncPrimaryContact($customer, $data);
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

        $tenantId = $request->user()->tenant_id;
        $orders = SalesOrder::query()
            ->where('tenant_id', $tenantId)->where('customer_id', $customer->id)
            ->select('id', 'code', 'total_amount', 'status', 'delivery_status', 'payment_status', 'created_at')
            ->latest('id')->take(8)->get();
        $orderIds = SalesOrder::where('tenant_id', $tenantId)->where('customer_id', $customer->id)->select('id');
        $payments = CustomerPayment::query()
            ->where('tenant_id', $tenantId)->whereIn('sales_order_id', $orderIds)
            ->select('id', 'code', 'sales_order_id', 'amount', 'payment_method', 'paid_at', 'created_at')
            ->latest('id')->take(8)->get();

        $customer360 = [
            'summary' => [
                'quotation_count' => Quotation::query()->where('tenant_id', $tenantId)->where('customer_id', $customer->id)->count(),
                'order_count' => SalesOrder::query()->where('tenant_id', $tenantId)->where('customer_id', $customer->id)->count(),
                'order_value' => (float) SalesOrder::query()->where('tenant_id', $tenantId)->where('customer_id', $customer->id)->whereNotIn('status', ['draft', 'cancelled', 'rejected'])->sum('total_amount'),
                'payment_value' => (float) CustomerPayment::query()->where('tenant_id', $tenantId)->whereHas('salesOrder', fn ($query) => $query->where('customer_id', $customer->id))->sum('amount'),
                'open_ticket_count' => ServiceTicket::query()->where('tenant_id', $tenantId)->where('customer_id', $customer->id)->whereNotIn('status', ['resolved', 'closed'])->count(),
            ],
            'leads' => Lead::query()->where('tenant_id', $tenantId)->where('customer_id', $customer->id)
                ->select('id', 'code', 'name', 'source', 'status', 'created_at')->latest('id')->take(6)->get(),
            'deals' => Deal::query()->where('tenant_id', $tenantId)->where('customer_id', $customer->id)
                ->with('owner:id,name')->select('id', 'code', 'name', 'stage', 'status', 'amount', 'probability', 'expected_close_date', 'owner_id', 'created_at')->latest('id')->take(6)->get(),
            'quotations' => Quotation::query()->where('tenant_id', $tenantId)->where('customer_id', $customer->id)
                ->select('id', 'code', 'total_amount', 'status', 'valid_until', 'created_at')->latest('id')->take(8)->get(),
            'orders' => $orders,
            'contracts' => Contract::query()->where('tenant_id', $tenantId)->where('customer_id', $customer->id)
                ->select('id', 'code', 'name', 'total_amount', 'status', 'effective_date', 'expiry_date', 'created_at')->latest('id')->take(6)->get(),
            'tickets' => ServiceTicket::query()->where('tenant_id', $tenantId)->where('customer_id', $customer->id)
                ->with('assignee:id,name')->select('id', 'code', 'subject', 'category', 'priority', 'status', 'assignee_id', 'created_at')->latest('id')->take(8)->get(),
            'payments' => $payments,
        ];

        return response()->json(['data' => $customer->load(['salesOwner:id,name,email', 'contacts']), 'meta' => ['customer_360' => $customer360]]);
    }

    public function duplicates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tax_code' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'exclude_id' => ['nullable', 'integer'],
        ]);
        $taxCode = $this->normalizeTaxCode($data['tax_code'] ?? null);
        $phone = preg_replace('/[^0-9+]/', '', (string) ($data['phone'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if (! $taxCode && ! $phone && ! $email) {
            return response()->json(['data' => []]);
        }

        $query = Customer::query()->where('tenant_id', $request->user()->tenant_id);
        $query->whereNull('merged_into_id');
        DataScope::owned($query, $request->user(), 'sales_owner_id', 'salesOwner');
        if (! empty($data['exclude_id'])) $query->whereKeyNot($data['exclude_id']);
        $query->where(function ($matches) use ($taxCode, $phone, $email) {
            if ($taxCode) $matches->orWhere('tax_code', $taxCode);
            if ($phone) $matches->orWhereRaw("REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '.', '') = ?", [$phone]);
            if ($email) $matches->orWhereRaw('LOWER(email) = ?', [$email]);
        });

        return response()->json(['data' => $query->select('id', 'code', 'name', 'tax_code', 'phone', 'email')->limit(10)->get()]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);
        abort_if($customer->merged_into_id, 409, 'Hồ sơ đã gộp, vui lòng sửa hồ sơ được giữ lại.');
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'primary_contact_id' => ['nullable', 'integer', Rule::exists('customer_contacts', 'id')->where('customer_id', $customer->id)],
            'name' => ['required', 'string', 'max:255'],
            'customer_type' => ['nullable', Rule::in(['person', 'organization'])],
            'identity_number' => ['nullable', 'regex:/^[0-9]{12}$/', Rule::unique('customers', 'identity_number')->where('tenant_id', $tenantId)->ignore($customer->id)],
            'tax_code' => [
                'nullable',
                'string',
                'max:30',
            ],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'legal_representative' => ['nullable', 'string', 'max:255'],
            'representative_position' => ['nullable', 'string', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'sales_owner_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $data['tax_code'] = $this->normalizeTaxCode($data['tax_code'] ?? null);
        if ($data['tax_code'] && Customer::where('tenant_id', $tenantId)->whereKeyNot($customer->id)->where('tax_code', $data['tax_code'])->exists()) {
            return response()->json(['message' => 'Mã số thuế đã thuộc một khách hàng trong hệ thống.'], 409);
        }

        $old = $customer->toArray();
        DB::transaction(function () use ($customer, $data) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            abort_if(($data['customer_type'] ?? $customer->customer_type) === 'person' && $customer->contacts()->exists(), 422, 'Tổ chức đang có người liên hệ. Không thể đổi sang khách cá nhân.');
            $customer->update(collect($data)->except('primary_contact_id')->all());
            $this->syncPrimaryContact($customer, $data);
        });
        $this->audit->record('customer', $customer->id, 'update_customer', $request->user(), $old, $customer->fresh()->toArray(), $request);

        return response()->json(['data' => $customer->refresh()->load('salesOwner:id,name,email')]);
    }

    private function normalizeTaxCode(?string $taxCode): ?string
    {
        $value = preg_replace('/[^0-9-]/', '', trim((string) $taxCode));

        return $value !== '' ? $value : null;
    }

    private function syncPrimaryContact(Customer $customer, array $data): void
    {
        if ($customer->customer_type !== 'organization') return;
        $contactId = $data['primary_contact_id'] ?? null;
        if ($contactId) {
            $contact = $customer->contacts()->findOrFail($contactId);
            $customer->contacts()->whereKeyNot($contact->id)->update(['is_primary' => false]);
            $contact->update(['is_primary' => true]);
            return;
        }
        $primary = $customer->contacts()->where('is_primary', true)->first();
        if ($primary) {
            // Editing the customer's primary fields edits the linked contact.
            $values = ['is_primary' => true];
            foreach (['contact_name' => 'name', 'phone' => 'phone', 'email' => 'email'] as $source => $target) {
                if (array_key_exists($source, $data)) $values[$target] = $data[$source];
            }
            abort_if(array_key_exists('name', $values) && !trim((string) $values['name']), 422, 'Người liên hệ chính cần có họ tên.');
            $primary->update($values);
        } elseif (!empty($data['contact_name'])) {
            $customer->contacts()->create(['name' => $data['contact_name'], 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'is_primary' => true]);
        }
    }

    public function storeDirectoryContact(Request $request): JsonResponse
    {
        $data = $this->validateContact($request);
        $customerData = $request->validate(['customer_id' => ['nullable', 'integer'], 'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255']]);
        return DB::transaction(function () use ($request, $data, $customerData) {
            $tenantId = $request->user()->tenant_id;
            if (!empty($customerData['customer_id'])) {
                $customer = Customer::where('tenant_id', $tenantId)->findOrFail($customerData['customer_id']);
                $this->authorizeCustomer($request, $customer);
            } else {
                $name = trim($customerData['customer_name']);
                abort_if($name === '', 422, 'Nhập tên khách hàng.');
                abort_if(Customer::where('tenant_id', $tenantId)->whereNull('merged_into_id')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->exists(), 422, 'Tên khách hàng đã có. Vui lòng chọn hồ sơ trong danh sách gợi ý.');
                $customer = Customer::create(['tenant_id' => $tenantId, 'code' => $this->codes->next('customers', 'code', 'CUS-', fn ($query) => $query->where('tenant_id', $tenantId)), 'name' => $name, 'customer_type' => 'organization', 'sales_owner_id' => $request->user()->id, 'status' => 'active']);
                $this->audit->record('customer', $customer->id, 'create_customer', $request->user(), null, $customer->toArray(), $request);
                $this->events->publish($tenantId, 'CustomerCreated', 'Customer', $customer->id, ['code' => $customer->code]);
            }
            abort_if($customer->customer_type !== 'organization', 422, 'Chỉ khách tổ chức mới có danh sách người liên hệ.');
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if (!$customer->contacts()->exists()) $data['is_primary'] = true;
            if ($data['is_primary'] ?? false) $customer->contacts()->update(['is_primary' => false]);
            $contact = $customer->contacts()->create($data);
            $this->audit->record('customer_contact', $contact->id, 'create_customer_contact', $request->user(), null, $contact->toArray(), $request);
            return response()->json(['data' => $contact->load('customer:id,code,name')], 201);
        });
    }

    public function storeContact(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);
        abort_if($customer->customer_type !== 'organization', 422, 'Chỉ khách tổ chức mới có danh sách người liên hệ.');
        $data = $this->validateContact($request);
        $contact = DB::transaction(function () use ($customer, $data) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if (! $customer->contacts()->exists()) $data['is_primary'] = true;
            if ($data['is_primary'] ?? false) $customer->contacts()->update(['is_primary' => false]);
            return $customer->contacts()->create($data);
        });
        $this->audit->record('customer_contact', $contact->id, 'create_customer_contact', $request->user(), null, $contact->toArray(), $request);
        return response()->json(['data' => $contact], 201);
    }

    public function updateContact(Request $request, Customer $customer, CustomerContact $contact): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);
        abort_if($contact->customer_id !== $customer->id, 404);
        $data = $this->validateContact($request);
        $old = $contact->toArray();
        DB::transaction(function () use ($customer, $contact, $data) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($data['is_primary'] ?? false) $customer->contacts()->whereKeyNot($contact->id)->update(['is_primary' => false]);
            $contact->update($data);
            if (! $customer->contacts()->where('is_primary', true)->exists()) {
                $customer->contacts()->first()?->update(['is_primary' => true]);
            }
        });
        $this->audit->record('customer_contact', $contact->id, 'update_customer_contact', $request->user(), $old, $contact->fresh()->toArray(), $request);
        return response()->json(['data' => $contact->refresh()]);
    }

    public function deleteContact(Request $request, Customer $customer, CustomerContact $contact): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);
        abort_if($contact->customer_id !== $customer->id, 404);
        $old = $contact->toArray();
        DB::transaction(function () use ($customer, $contact) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $wasPrimary = $contact->is_primary;
            $contact->delete();
            if ($wasPrimary) $customer->contacts()->first()?->update(['is_primary' => true]);
            if ($wasPrimary && !$customer->contacts()->exists()) $customer->update(['contact_name' => null, 'phone' => null, 'email' => null]);
        });
        $this->audit->record('customer_contact', $contact->id, 'delete_customer_contact', $request->user(), $old, null, $request);
        return response()->json(['message' => 'Đã xóa người liên hệ.']);
    }

    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        abort_if($customer->tenant_id !== $request->user()->tenant_id, 404);
        abort_if($customer->merged_into_id, 409, 'Hồ sơ đã gộp, vui lòng sửa hồ sơ được giữ lại.');
        abort_unless(DataScope::owned(Customer::whereKey($customer->id), $request->user(), 'sales_owner_id', 'salesOwner')->exists(), 404);
    }

    private function validateContact(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
        ]);
    }
}
