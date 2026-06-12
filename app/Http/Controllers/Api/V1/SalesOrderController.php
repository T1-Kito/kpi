<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\GoodsIssue;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Task;
use App\Support\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesOrderController extends Controller
{
    public function __construct(private readonly SalesOrderService $orders)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = SalesOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['customer:id,code,name', 'items.sku:id,sku_code,name']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $orders = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $orders->items(),
            'meta' => ['page' => $orders->currentPage(), 'page_size' => $orders->perPage(), 'total' => $orders->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quotation_id' => ['required', Rule::exists('quotations', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        $quotation = Quotation::with('items')->findOrFail($data['quotation_id']);
        $order = $this->orders->createFromQuotation($quotation, $request->user());

        return response()->json(['data' => $order], 201);
    }

    public function show(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_if($salesOrder->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json(['data' => $this->withDetailContext(
            $salesOrder->load(['customer:id,code,name', 'items.sku:id,sku_code,name'])
        )]);
    }

    public function confirm(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_if($salesOrder->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json(['data' => $this->orders->confirm($salesOrder->load('items'), $request->user())]);
    }

    /** @return array<string, mixed> */
    private function withDetailContext(SalesOrder $order): array
    {
        $quotation = $order->quotation_id
            ? Quotation::where('tenant_id', $order->tenant_id)->whereKey($order->quotation_id)->first()
            : null;

        return [
            ...$order->toArray(),
            'related_documents' => array_merge(
                $quotation ? [[
                    'type' => 'quotation',
                    'label' => 'Báo giá',
                    'code' => $quotation->code,
                    'status' => $quotation->status,
                    'path' => '/quotations/'.$quotation->id,
                ]] : [],
                PurchaseRequest::where('tenant_id', $order->tenant_id)
                    ->where('source_type', 'SalesOrder')
                    ->where('source_id', $order->id)
                    ->latest('id')
                    ->get()
                    ->map(fn ($pr) => [
                        'type' => 'purchaseRequest',
                        'label' => 'Yêu cầu mua',
                        'code' => $pr->code,
                        'status' => $pr->status,
                        'path' => '/purchase-requests/'.$pr->id,
                    ])
                    ->all(),
                GoodsIssue::where('tenant_id', $order->tenant_id)
                    ->where('sales_order_id', $order->id)
                    ->latest('id')
                    ->get()
                    ->map(fn ($issue) => [
                        'type' => 'goodsIssue',
                        'label' => 'Phiếu xuất kho',
                        'code' => $issue->code,
                        'status' => $issue->status,
                        'path' => '/goods-issues/'.$issue->id,
                    ])
                    ->all(),
            ),
            'timeline' => [
                ['label' => 'Tạo đơn bán', 'status' => 'completed', 'at' => $order->created_at],
                ['label' => 'Kiểm tra tồn kho', 'status' => $order->stock_status, 'at' => $order->updated_at],
                ['label' => 'Trạng thái đơn', 'status' => $order->status, 'at' => $order->updated_at],
            ],
            'tasks' => Task::where('tenant_id', $order->tenant_id)->where('source_type', 'SalesOrder')->where('source_id', $order->id)->latest('id')->get(),
            'alerts' => Alert::where('tenant_id', $order->tenant_id)->where('source_type', 'SalesOrder')->where('source_id', $order->id)->latest('id')->get(),
        ];
    }
}
