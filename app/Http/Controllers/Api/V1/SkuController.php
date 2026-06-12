<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sku;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use App\Support\BusinessEventPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SkuController extends Controller
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
        $query = Sku::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('product:id,code,name');

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q
                ->where('sku_code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%"));
        }

        $skus = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $skus->items(),
            'meta' => ['page' => $skus->currentPage(), 'page_size' => $skus->perPage(), 'total' => $skus->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'product_name' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:30'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $product = Product::create([
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('products', 'code', 'PROD-', fn ($query) => $query->where('tenant_id', $tenantId)),
            'name' => $data['product_name'] ?? $data['name'],
            'status' => 'active',
        ]);

        $sku = Sku::create([
            'tenant_id' => $tenantId,
            'product_id' => $product?->id,
            'sku_code' => $this->codes->next('skus', 'sku_code', 'SKU-', fn ($query) => $query->where('tenant_id', $tenantId)),
            'name' => $data['name'],
            'barcode' => $data['barcode'] ?? null,
            'unit' => $data['unit'] ?? 'pcs',
            'min_stock' => $data['min_stock'] ?? 0,
            'max_stock' => $data['max_stock'] ?? 0,
            'sale_price' => $data['sale_price'] ?? 0,
            'cost_price' => $data['cost_price'] ?? 0,
            'status' => $data['status'] ?? 'active',
        ]);

        $this->audit->record('sku', $sku->id, 'create_sku', $request->user(), null, $sku->toArray(), $request);
        $this->events->publish($tenantId, 'SkuCreated', 'Sku', $sku->id, ['sku_code' => $sku->sku_code]);

        return response()->json(['data' => $sku->load('product:id,code,name')], 201);
    }

    public function show(Request $request, Sku $sku): JsonResponse
    {
        abort_if($sku->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json(['data' => $sku->load('product:id,code,name')]);
    }

    public function update(Request $request, Sku $sku): JsonResponse
    {
        abort_if($sku->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'product_name' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:30'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $product = $sku->product;
        if ($product) {
            $product->update([
                'name' => $data['product_name'] ?? $product->name,
                'status' => 'active',
            ]);
        } else {
            $product = Product::create([
                'tenant_id' => $tenantId,
                'code' => $this->codes->next('products', 'code', 'PROD-', fn ($query) => $query->where('tenant_id', $tenantId)),
                'name' => $data['product_name'] ?? $data['name'],
                'status' => 'active',
            ]);
        }

        $old = $sku->toArray();
        $sku->update([
            'product_id' => $product?->id,
            'name' => $data['name'],
            'barcode' => $data['barcode'] ?? null,
            'unit' => $data['unit'] ?? 'pcs',
            'min_stock' => $data['min_stock'] ?? 0,
            'max_stock' => $data['max_stock'] ?? 0,
            'sale_price' => $data['sale_price'] ?? 0,
            'cost_price' => $data['cost_price'] ?? 0,
            'status' => $data['status'] ?? 'active',
        ]);
        $this->audit->record('sku', $sku->id, 'update_sku', $request->user(), $old, $sku->fresh()->toArray(), $request);

        return response()->json(['data' => $sku->refresh()->load('product:id,code,name')]);
    }
}
