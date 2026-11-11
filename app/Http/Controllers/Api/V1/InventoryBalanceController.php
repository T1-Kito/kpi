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
        $pageSize = max(1,min((int) $request->query('page_size', 20), 100));
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

        if ($search = trim((string)$request->query('q',''))) $query->where(fn($q)=>$q->where('lot_no','like',"%{$search}%")->orWhereHas('sku',fn($sku)=>$sku->where('sku_code','like',"%{$search}%")->orWhere('name','like',"%{$search}%"))->orWhereHas('warehouse',fn($warehouse)=>$warehouse->where('name','like',"%{$search}%")));
        $status=$request->query('status');
        if($status==='reserved')$query->where('reserved','>',0);
        if(in_array($status,['low','ok'],true))$query->whereHas('sku',fn($sku)=>$sku->whereColumn('inventory_balances.available',$status==='low'?'<=':'>','skus.min_stock'));
        $balances = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $balances->items(),
            'meta' => ['page' => $balances->currentPage(), 'page_size' => $balances->perPage(), 'total' => $balances->total()],
        ]);
    }
}
