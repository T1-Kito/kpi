<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'sku_id' => ['nullable', 'integer'], 'warehouse_id' => ['nullable', 'integer'], 'lot_no' => ['nullable', 'string', 'max:255'], 'type' => ['nullable', 'string', 'max:50'], 'q' => ['nullable', 'string', 'max:255']]);
        abort_if($request->filled('from') && $request->filled('to') && \Carbon\Carbon::parse($request->query('from'))->gt(\Carbon\Carbon::parse($request->query('to'))), 422, 'Ngày kết thúc phải từ ngày bắt đầu trở đi.');
        $query = InventoryTransaction::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['sku:id,sku_code,name', 'warehouse:id,code,name']);
        \App\Support\DataScope::warehouseScope($query, $request->user());
        foreach (['sku_id', 'warehouse_id'] as $field) if ($request->filled($field)) $query->where($field, $request->query($field));
        if ($request->has('lot_no')) $query->where(fn ($q) => $request->filled('lot_no') ? $q->where('lot_no', $request->query('lot_no')) : $q->whereNull('lot_no')->orWhere('lot_no', ''));
        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $query->where(fn ($q) => $q->where('lot_no', 'like', $term)->orWhereHas('sku', fn ($s) => $s->where('sku_code', 'like', $term)->orWhere('name', 'like', $term)));
        }
        $summary = null;
        if ($request->boolean('card') && $request->filled('sku_id') && $request->filled('warehouse_id')) {
            $balances = \App\Models\InventoryBalance::where('tenant_id', $request->user()->tenant_id)->where('sku_id', $request->query('sku_id'))->where('warehouse_id', $request->query('warehouse_id'));
            \App\Support\DataScope::warehouseScope($balances, $request->user());
            if ($request->has('lot_no')) $balances->where(fn ($q) => $request->filled('lot_no') ? $q->where('lot_no', $request->query('lot_no')) : $q->whereNull('lot_no')->orWhere('lot_no', ''));
            $current = (float) $balances->sum('on_hand');
            $base = clone $query;
            $opening = $current - (float) (clone $base)->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->query('from')))->sum('quantity');
            $summary = ['opening' => $opening, 'current' => $current];
        }
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->query('from'));
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->query('to'));
        if ($summary !== null) {
            $summary['in'] = (float) (clone $query)->where('quantity', '>', 0)->sum('quantity');
            $summary['out'] = abs((float) (clone $query)->where('quantity', '<', 0)->sum('quantity'));
            $summary['closing'] = $summary['opening'] + $summary['in'] - $summary['out'];
        }
        if ($request->filled('type')) $query->where('transaction_type', $request->query('type'));
        $rows = $query->latest('id')->paginate(max(1, $pageSize));
        foreach (['GoodsReceipt' => \App\Models\GoodsReceipt::class, 'GoodsIssue' => \App\Models\GoodsIssue::class] as $type => $model) {
            $ids = $rows->getCollection()->where('source_type', $type)->pluck('source_id');
            $codes = $model::where('tenant_id', $request->user()->tenant_id)->whereIn('id', $ids)->pluck('code', 'id');
            foreach ($rows as $row) if ($row->source_type === $type) $row->setAttribute('source_code', $codes[$row->source_id] ?? null);
        }

        return response()->json([
            'data' => $rows->items(),
            'meta' => ['page' => $rows->currentPage(), 'page_size' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage(), 'summary' => $summary],
        ]);
    }
}
