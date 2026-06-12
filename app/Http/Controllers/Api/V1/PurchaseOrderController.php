<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Task;
use App\Services\Procurement\ProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly ProcurementService $procurement)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = PurchaseOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['purchaseRequest:id,code', 'supplier:id,code,name', 'items.sku:id,sku_code,name']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

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
            'purchase_request_id' => ['required', Rule::exists('purchase_requests', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'expected_delivery_date' => ['nullable', 'date'],
        ]);

        $pr = PurchaseRequest::with('items.sku')->findOrFail($data['purchase_request_id']);

        return response()->json([
            'data' => $this->procurement->createPoFromPr($pr, $request->user(), (int) $data['supplier_id'], $data['expected_delivery_date'] ?? null),
        ], 201);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_if($purchaseOrder->tenant_id !== $request->user()->tenant_id, 404);

        $purchaseOrder->load(['purchaseRequest:id,code,source_type,source_id,status', 'supplier:id,code,name', 'items.sku:id,sku_code,name']);

        return response()->json(['data' => [
            ...$purchaseOrder->toArray(),
            'related_documents' => array_merge(
                $purchaseOrder->purchaseRequest ? [[
                    'type' => 'purchaseRequest',
                    'label' => 'Yêu cầu mua',
                    'code' => $purchaseOrder->purchaseRequest->code,
                    'status' => $purchaseOrder->purchaseRequest->status,
                    'path' => '/purchase-requests/'.$purchaseOrder->purchaseRequest->id,
                ]] : [],
                GoodsReceipt::where('tenant_id', $purchaseOrder->tenant_id)
                    ->where('purchase_order_id', $purchaseOrder->id)
                    ->latest('id')
                    ->get()
                    ->map(fn ($receipt) => [
                        'type' => 'goodsReceipt',
                        'label' => 'Phiếu nhập kho',
                        'code' => $receipt->code,
                        'status' => $receipt->status,
                        'path' => '/goods-receipts/'.$receipt->id,
                    ])
                    ->all(),
            ),
            'timeline' => [
                ['label' => 'Tạo đơn mua', 'status' => 'completed', 'at' => $purchaseOrder->created_at],
                ['label' => 'Duyệt đơn mua', 'status' => $purchaseOrder->status === 'draft' ? 'pending' : 'approved', 'at' => $purchaseOrder->updated_at],
                ['label' => 'Nhận hàng', 'status' => $purchaseOrder->status, 'at' => $purchaseOrder->updated_at],
            ],
            'tasks' => Task::where('tenant_id', $purchaseOrder->tenant_id)->where('source_type', 'PurchaseOrder')->where('source_id', $purchaseOrder->id)->latest('id')->get(),
            'alerts' => Alert::where('tenant_id', $purchaseOrder->tenant_id)->where('source_type', 'PurchaseOrder')->where('source_id', $purchaseOrder->id)->latest('id')->get(),
        ]]);
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_if($purchaseOrder->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->procurement->approvePo($purchaseOrder, $request->user(), $data['reason'] ?? null)]);
    }
}
