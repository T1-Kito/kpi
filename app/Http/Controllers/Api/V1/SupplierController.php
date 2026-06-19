<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use App\Support\BusinessEventPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
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
        $query = Supplier::query()->where('tenant_id', $request->user()->tenant_id);

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }

        $suppliers = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $suppliers->items(),
            'meta' => ['page' => $suppliers->currentPage(), 'page_size' => $suppliers->perPage(), 'total' => $suppliers->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'terms' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'supplied_products' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $supplier = Supplier::create($data + [
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('suppliers', 'code', 'SUP-', fn ($query) => $query->where('tenant_id', $tenantId)),
            'status' => $data['status'] ?? 'active',
        ]);
        $this->audit->record('supplier', $supplier->id, 'create_supplier', $request->user(), null, $supplier->toArray(), $request);
        $this->events->publish($tenantId, 'SupplierCreated', 'Supplier', $supplier->id, ['code' => $supplier->code]);

        return response()->json(['data' => $supplier], 201);
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        abort_if($supplier->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json(['data' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        abort_if($supplier->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'terms' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'supplied_products' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $old = $supplier->toArray();
        $supplier->update($data);
        $this->audit->record('supplier', $supplier->id, 'update_supplier', $request->user(), $old, $supplier->fresh()->toArray(), $request);

        return response()->json(['data' => $supplier->refresh()]);
    }
}
