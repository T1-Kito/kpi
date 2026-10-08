<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
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
            ->with(['product:id,code,name,category_id,brand_id,image_path,description,technical_specs', 'product.category:id,name', 'product.brand:id,name']);

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q
                ->where('sku_code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhere('serial_number', 'like', "%{$search}%"));
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
            'product_name' => ['nullable', 'string', 'max:255'], 'category_id' => ['nullable', Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId)], 'brand_id' => ['nullable', Rule::exists('product_brands', 'id')->where('tenant_id', $tenantId)],
            'product_image' => ['nullable', 'image', 'max:4096'], 'description' => ['nullable', 'string', 'max:5000'], 'technical_specs' => ['nullable', 'string', 'max:5000'],
            'name' => ['required', 'string', 'max:255'], 'serial_number' => ['nullable', 'string', 'max:120'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:30'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'quick_create' => ['sometimes', 'boolean'],
        ]);

        if ($data['quick_create'] ?? false) {
            $duplicate = Sku::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($data['name']))])
                ->where('unit', trim($data['unit'] ?? 'pcs'))->exists();
            abort_if($duplicate, 422, 'Mặt hàng cùng tên và đơn vị tính đã có. Hãy chọn mã có sẵn để tránh trùng.');
            $data['name'] = trim($data['name']);
        }

        $product = Product::create([
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('products', 'code', 'PROD-', fn ($query) => $query->where('tenant_id', $tenantId)),
            'name' => $data['product_name'] ?? $data['name'],
            ...$this->productDetails($request, $data, $tenantId),
            'status' => 'active',
        ]);

        $sku = Sku::create([
            'tenant_id' => $tenantId,
            'product_id' => $product?->id,
            'sku_code' => $this->codes->next('skus', 'sku_code', 'SKU-', fn ($query) => $query->where('tenant_id', $tenantId)),
            'name' => $data['name'],
            'serial_number' => $data['serial_number'] ?? null,
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

        return response()->json(['data' => $sku->load(['product.category:id,name', 'product.brand:id,name'])], 201);
    }

    public function show(Request $request, Sku $sku): JsonResponse
    {
        abort_if($sku->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json(['data' => $sku->load(['product.category:id,name', 'product.brand:id,name'])]);
    }

    public function update(Request $request, Sku $sku): JsonResponse
    {
        abort_if($sku->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'product_name' => ['nullable', 'string', 'max:255'], 'category_id' => ['nullable', Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId)], 'brand_id' => ['nullable', Rule::exists('product_brands', 'id')->where('tenant_id', $tenantId)],
            'product_image' => ['nullable', 'image', 'max:4096'], 'description' => ['nullable', 'string', 'max:5000'], 'technical_specs' => ['nullable', 'string', 'max:5000'],
            'name' => ['required', 'string', 'max:255'], 'serial_number' => ['nullable', 'string', 'max:120'],
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
                ...$this->productDetails($request, $data, $tenantId, $product),
                'status' => 'active',
            ]);
        } else {
            $product = Product::create([
                'tenant_id' => $tenantId,
                'code' => $this->codes->next('products', 'code', 'PROD-', fn ($query) => $query->where('tenant_id', $tenantId)),
                'name' => $data['product_name'] ?? $data['name'],
                ...$this->productDetails($request, $data, $tenantId),
                'status' => 'active',
            ]);
        }

        $old = $sku->toArray();
        $sku->update([
            'product_id' => $product?->id,
            'name' => $data['name'],
            'serial_number' => $data['serial_number'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'unit' => $data['unit'] ?? 'pcs',
            'min_stock' => $data['min_stock'] ?? 0,
            'max_stock' => $data['max_stock'] ?? 0,
            'sale_price' => $data['sale_price'] ?? 0,
            'cost_price' => $data['cost_price'] ?? 0,
            'status' => $data['status'] ?? 'active',
        ]);
        $this->audit->record('sku', $sku->id, 'update_sku', $request->user(), $old, $sku->fresh()->toArray(), $request);

        return response()->json(['data' => $sku->refresh()->load(['product.category:id,name', 'product.brand:id,name'])]);
    }

    private function productDetails(Request $request, array $data, int $tenantId, ?Product $existing = null): array
    {
        $details = ['category_id' => $data['category_id'] ?? null, 'brand_id' => $data['brand_id'] ?? null, 'description' => $data['description'] ?? null, 'technical_specs' => $this->parseSpecs($data['technical_specs'] ?? null)];
        if ($existing) {
            foreach (array_keys($details) as $field) {
                if (!array_key_exists($field, $data)) unset($details[$field]);
            }
        }
        if ($request->hasFile('product_image')) $details['image_path'] = $request->file('product_image')->store("tenants/{$tenantId}/products", 'public');
        elseif ($existing) unset($details['image_path']);
        return $details;
    }

    private function parseSpecs(?string $value): ?array
    {
        if (! $value) return null;
        return collect(preg_split('/\\r?\\n/', $value))->map(fn ($line) => trim($line))->filter()->map(function ($line) { [$key, $detail] = array_pad(explode(':', $line, 2), 2, ''); return ['name' => trim($key), 'value' => trim($detail)]; })->values()->all();
    }
}
