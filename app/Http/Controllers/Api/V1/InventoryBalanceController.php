<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryBalanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = InventoryBalance::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['sku:id,sku_code,name,unit,min_stock,max_stock', 'warehouse:id,code,name']);
        DataScope::warehouseScope($query, $request->user());

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($skuId = $request->query('sku_id')) {
            $query->where('sku_id', $skuId);
        }

        $balances = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $balances->items(),
            'meta' => ['page' => $balances->currentPage(), 'page_size' => $balances->perPage(), 'total' => $balances->total()],
        ]);
    }
}
