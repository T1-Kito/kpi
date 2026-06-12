<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use App\Support\BusinessEventPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
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
        $warehouses = Warehouse::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('locations:id,warehouse_id,code,name,status')
            ->latest('id')
            ->paginate($pageSize);

        return response()->json([
            'data' => $warehouses->items(),
            'meta' => ['page' => $warehouses->currentPage(), 'page_size' => $warehouses->perPage(), 'total' => $warehouses->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'manager_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'locations' => ['nullable', 'array'],
            'locations.*.code' => ['nullable', 'string', 'max:50'],
            'locations.*.name' => ['required_with:locations', 'string', 'max:255'],
        ]);

        $warehouse = Warehouse::create([
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('warehouses', 'code', 'WH-', fn ($query) => $query->where('tenant_id', $tenantId)),
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        foreach ($data['locations'] ?? [] as $location) {
            $warehouse->locations()->create([
                'code' => $location['code'] ?? $this->codes->next('warehouse_locations', 'code', 'LOC-'),
                'name' => $location['name'],
                'status' => $location['status'] ?? 'active',
            ]);
        }

        $this->audit->record('warehouse', $warehouse->id, 'create_warehouse', $request->user(), null, $warehouse->toArray(), $request);
        $this->events->publish($tenantId, 'WarehouseCreated', 'Warehouse', $warehouse->id, ['code' => $warehouse->code]);

        return response()->json(['data' => $warehouse->load('locations:id,warehouse_id,code,name,status')], 201);
    }

    public function show(Request $request, Warehouse $warehouse): JsonResponse
    {
        abort_if($warehouse->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json(['data' => $warehouse->load('locations:id,warehouse_id,code,name,status')]);
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        abort_if($warehouse->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'manager_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $old = $warehouse->toArray();
        $warehouse->update($data);
        $this->audit->record('warehouse', $warehouse->id, 'update_warehouse', $request->user(), $old, $warehouse->fresh()->toArray(), $request);

        return response()->json(['data' => $warehouse->refresh()->load('locations:id,warehouse_id,code,name,status')]);
    }
}
