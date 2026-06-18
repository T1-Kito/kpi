<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\GoodsIssue;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Task;
use App\Services\Inventory\InventoryService;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoodsIssueController extends Controller
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = GoodsIssue::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with([
                'salesOrder:id,code,customer_id,quotation_id,total_amount,created_at,status,stock_status',
                'salesOrder.customer:id,name',
                'warehouse:id,code,name',
                'items.sku:id,sku_code,name,unit',
            ]);
        DataScope::warehouseScope($query, $request->user());
        $rows = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $rows->items(),
            'meta' => ['page' => $rows->currentPage(), 'page_size' => $rows->perPage(), 'total' => $rows->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'sales_order_id' => ['required', Rule::exists('sales_orders', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'warehouse_location_id' => ['nullable', 'integer'],
        ]);
        $order = SalesOrder::with('items')->findOrFail($data['sales_order_id']);

        return response()->json([
            'data' => $this->inventory->createIssueFromSalesOrder($order, $request->user(), (int) $data['warehouse_id'], $data['warehouse_location_id'] ?? null),
        ], 201);
    }

    public function show(Request $request, GoodsIssue $goodsIssue): JsonResponse
    {
        abort_if($goodsIssue->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::warehouseScope(GoodsIssue::whereKey($goodsIssue->id), $request->user())->exists(), 404);

        $goodsIssue->load([
            'salesOrder:id,code,customer_id,status,stock_status,quotation_id,total_amount,created_at',
            'salesOrder.customer:id,code,name,contact_name,phone',
            'warehouse:id,code,name',
            'items.sku:id,sku_code,name,unit',
        ]);
        $salesOrder = $goodsIssue->salesOrder;
        $quotation = $salesOrder?->quotation_id
            ? Quotation::where('tenant_id', $goodsIssue->tenant_id)->whereKey($salesOrder->quotation_id)->first()
            : null;

        return response()->json(['data' => [
            ...$goodsIssue->toArray(),
            'related_documents' => array_merge(
                $salesOrder ? [[
                    'type' => 'salesOrder',
                    'label' => 'Đơn bán',
                    'code' => $salesOrder->code,
                    'status' => $salesOrder->status,
                    'path' => '/sales-orders/'.$salesOrder->id,
                ]] : [],
                $quotation ? [[
                    'type' => 'quotation',
                    'label' => 'Báo giá',
                    'code' => $quotation->code,
                    'status' => $quotation->status,
                    'path' => '/quotations/'.$quotation->id,
                ]] : [],
            ),
            'timeline' => [
                ['label' => 'Tạo phiếu xuất', 'status' => 'completed', 'at' => $goodsIssue->created_at],
                ['label' => 'Xác nhận xuất kho', 'status' => $goodsIssue->status, 'at' => $goodsIssue->confirmed_at ?? $goodsIssue->updated_at],
                ['label' => 'Chờ xác nhận giao hàng', 'status' => $goodsIssue->status === 'confirmed' ? 'pending' : 'not_ready', 'at' => $goodsIssue->confirmed_at],
            ],
            'tasks' => Task::where('tenant_id', $goodsIssue->tenant_id)->where('source_type', 'GoodsIssue')->where('source_id', $goodsIssue->id)->latest('id')->get(),
            'alerts' => Alert::where('tenant_id', $goodsIssue->tenant_id)->where('source_type', 'GoodsIssue')->where('source_id', $goodsIssue->id)->latest('id')->get(),
        ]]);
    }

    public function confirm(Request $request, GoodsIssue $goodsIssue): JsonResponse
    {
        abort_if($goodsIssue->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json(['data' => $this->inventory->confirmIssue($goodsIssue->load('items'), $request->user())]);
    }
}
