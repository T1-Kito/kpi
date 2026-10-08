<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SalesOrder;
use App\Models\Task;
use App\Services\Inventory\InventoryService;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoodsReceiptController extends Controller
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = GoodsReceipt::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['purchaseOrder:id,code', 'warehouse:id,code,name', 'items.sku:id,sku_code,name,unit']);
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
            'purchase_order_id' => ['required', Rule::exists('purchase_orders', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'warehouse_location_id' => ['nullable', 'integer'],
        ]);
        $po = PurchaseOrder::with('items')->findOrFail($data['purchase_order_id']);

        return response()->json([
            'data' => $this->inventory->createReceiptFromPo($po, $request->user(), (int) $data['warehouse_id'], $data['warehouse_location_id'] ?? null),
        ], 201);
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        abort_if($goodsReceipt->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::warehouseScope(GoodsReceipt::whereKey($goodsReceipt->id), $request->user())->exists(), 404);

        $goodsReceipt->load([
            'purchaseOrder:id,code,status,purchase_request_id,supplier_id,expected_delivery_date,total_amount',
            'purchaseOrder.supplier:id,code,name',
            'warehouse:id,code,name',
            'items.sku:id,sku_code,name,unit',
        ]);
        $purchaseOrder = $goodsReceipt->purchaseOrder;
        $purchaseRequest = $purchaseOrder
            ? PurchaseRequest::where('tenant_id', $goodsReceipt->tenant_id)->whereKey($purchaseOrder->purchase_request_id)->first()
            : null;
        $salesOrder = $purchaseRequest && $purchaseRequest->source_type === 'SalesOrder' && $purchaseRequest->source_id
            ? SalesOrder::where('tenant_id', $goodsReceipt->tenant_id)->whereKey($purchaseRequest->source_id)->first()
            : null;

        return response()->json(['data' => [
            ...$goodsReceipt->toArray(),
            'related_documents' => array_merge(
                $purchaseOrder ? [[
                    'type' => 'purchaseOrder',
                    'label' => 'Đơn mua',
                    'code' => $purchaseOrder->code,
                    'status' => $purchaseOrder->status,
                    'path' => '/purchase-orders/'.$purchaseOrder->id,
                ]] : [],
                $purchaseRequest ? [[
                    'type' => 'purchaseRequest',
                    'label' => 'Yêu cầu mua',
                    'code' => $purchaseRequest->code,
                    'status' => $purchaseRequest->status,
                    'path' => '/purchase-requests/'.$purchaseRequest->id,
                ]] : [],
                $salesOrder ? [[
                    'type' => 'salesOrder',
                    'label' => 'Đơn bán nguồn',
                    'code' => $salesOrder->code,
                    'status' => $salesOrder->status,
                    'path' => '/sales-orders/'.$salesOrder->id,
                ]] : [],
            ),
            'timeline' => [
                ['label' => 'Tạo phiếu nhập', 'status' => 'completed', 'at' => $goodsReceipt->created_at],
                ...collect($goodsReceipt->approval_flow ?? [])->map(fn ($step, $index) => ['label' => 'Cấp '.($index + 1).' · '.$step['name'], 'status' => !empty($step['decided_at']) ? 'completed' : 'pending', 'at' => $step['decided_at'] ?? null])->all(),
                ['label' => 'Xác nhận nhập kho', 'status' => $goodsReceipt->status, 'at' => $goodsReceipt->confirmed_at ?? $goodsReceipt->updated_at],
                ['label' => 'Cập nhật tồn kho', 'status' => $goodsReceipt->status === 'confirmed' ? 'completed' : 'pending', 'at' => $goodsReceipt->confirmed_at],
            ],
            'tasks' => Task::where('tenant_id', $goodsReceipt->tenant_id)->where('source_type', 'GoodsReceipt')->where('source_id', $goodsReceipt->id)->latest('id')->get(),
            'alerts' => Alert::where('tenant_id', $goodsReceipt->tenant_id)->where('source_type', 'GoodsReceipt')->where('source_id', $goodsReceipt->id)->latest('id')->get(),
        ]]);
    }

    public function confirm(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        abort_if($goodsReceipt->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::warehouseScope(GoodsReceipt::whereKey($goodsReceipt->id), $request->user())->exists(), 404);

        return response()->json(['data' => $this->inventory->confirmReceipt($goodsReceipt->load('items'), $request->user())]);
    }
}
