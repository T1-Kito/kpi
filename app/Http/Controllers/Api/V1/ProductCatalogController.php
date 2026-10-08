<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductCatalogController extends Controller
{
    public function categories(Request $request): JsonResponse { return response()->json(['data' => ProductCategory::where('tenant_id', $request->user()->tenant_id)->orderBy('name')->get(['id','name','status'])]); }
    public function brands(Request $request): JsonResponse { return response()->json(['data' => ProductBrand::where('tenant_id', $request->user()->tenant_id)->orderBy('name')->get(['id','name','status'])]); }
    public function storeCategory(Request $request): JsonResponse { return $this->store($request, ProductCategory::class); }
    public function storeBrand(Request $request): JsonResponse { return $this->store($request, ProductBrand::class); }
    private function store(Request $request, string $model): JsonResponse {
        $data = $request->validate(['name' => ['required','string','max:100'], 'status' => ['nullable', Rule::in(['active','inactive'])]]);
        $item = $model::firstOrCreate(['tenant_id' => $request->user()->tenant_id, 'name' => trim($data['name'])], ['status' => $data['status'] ?? 'active']);
        return response()->json(['data' => $item], $item->wasRecentlyCreated ? 201 : 200);
    }
}
