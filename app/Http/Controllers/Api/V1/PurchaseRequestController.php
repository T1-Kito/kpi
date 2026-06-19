<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SalesOrder;
use App\Models\Task;
use App\Services\Procurement\ProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseRequestController extends Controller
{
    public function __construct(private readonly ProcurementService $procurement)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = PurchaseRequest::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['items.sku:id,sku_code,name,unit', 'requester:id,name']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $rows = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $rows->items(),
            'meta' => ['page' => $rows->currentPage(), 'page_size' => $rows->perPage(), 'total' => $rows->total()],
        ]);
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        abort_if($purchaseRequest->tenant_id !== $request->user()->tenant_id, 404);

        $purchaseRequest->load(['items.sku:id,sku_code,name,unit', 'requester:id,name']);
        $sourceOrder = $purchaseRequest->source_type === 'SalesOrder' && $purchaseRequest->source_id
            ? SalesOrder::where('tenant_id', $purchaseRequest->tenant_id)->whereKey($purchaseRequest->source_id)->first()
            : null;

        return response()->json(['data' => [
            ...$purchaseRequest->toArray(),
            'related_documents' => array_merge(
                $sourceOrder ? [[
                    'type' => 'salesOrder',
                    'label' => 'Đơn bán nguồn',
                    'code' => $sourceOrder->code,
                    'status' => $sourceOrder->status,
                    'path' => '/sales-orders/'.$sourceOrder->id,
                ]] : [],
                PurchaseOrder::where('tenant_id', $purchaseRequest->tenant_id)
                    ->where('purchase_request_id', $purchaseRequest->id)
                    ->latest('id')
                    ->get()
                    ->map(fn ($po) => [
                        'type' => 'purchaseOrder',
                        'label' => 'Đơn mua',
                        'code' => $po->code,
                        'status' => $po->status,
                        'path' => '/purchase-orders/'.$po->id,
                    ])
                    ->all(),
            ),
            'timeline' => [
                ['label' => 'Tạo yêu cầu mua', 'status' => 'completed', 'at' => $purchaseRequest->created_at],
                ['label' => 'Trạng thái duyệt', 'status' => $purchaseRequest->status, 'at' => $purchaseRequest->updated_at],
            ],
            'tasks' => Task::where('tenant_id', $purchaseRequest->tenant_id)->where('source_type', 'PurchaseRequest')->where('source_id', $purchaseRequest->id)->latest('id')->get(),
            'alerts' => Alert::where('tenant_id', $purchaseRequest->tenant_id)->where('source_type', 'PurchaseRequest')->where('source_id', $purchaseRequest->id)->latest('id')->get(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku_id' => ['required', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        return response()->json([
            'data' => $this->procurement->createManualPr($request->user(), $data['items'], $data['reason'] ?? null),
        ], 201);
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        abort_if($purchaseRequest->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->procurement->approvePr($purchaseRequest, $request->user(), $data['reason'] ?? null)]);
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        abort_if($purchaseRequest->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->procurement->rejectPr($purchaseRequest, $request->user(), $data['reason'])]);
    }
}
