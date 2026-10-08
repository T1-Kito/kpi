<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\GoodsIssue;
use App\Models\PrintTemplate;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Task;
use App\Services\Inventory\InventoryService;
use App\Services\Print\QuotationDocxMergeService;
use App\Support\DataScope;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoodsIssueController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly QuotationDocxMergeService $docxMerge,
    )
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
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:50'],
            'recipient_address' => ['nullable', 'string', 'max:2000'],
            'delivery_location' => ['nullable', 'string', 'max:255'],
            'issue_reason' => ['nullable', 'string', 'max:500'],
            'source_document' => ['nullable', 'string', 'max:255'],
        ]);
        $order = SalesOrder::with(['items', 'customer', 'quotation'])->findOrFail($data['sales_order_id']);

        return response()->json([
            'data' => $this->inventory->createIssueFromSalesOrder($order, $request->user(), (int) $data['warehouse_id'], $data['warehouse_location_id'] ?? null, $data),
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

    public function exportWord(Request $request, GoodsIssue $goodsIssue): BinaryFileResponse|JsonResponse
    {
        abort_if($goodsIssue->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::warehouseScope(GoodsIssue::whereKey($goodsIssue->id), $request->user())->exists(), 404);

        $template = PrintTemplate::where('tenant_id', $request->user()->tenant_id)
            ->where('module', 'goods_issue')
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->latest('id')
            ->first();

        if (! $template || ! $template->file_path) {
            return response()->json(['message' => 'Chưa có file Word cho mẫu Phiếu xuất kho. Vào Mẫu in để tải mẫu Phiếu xuất kho lên và đặt làm mặc định.'], 422);
        }

        $goodsIssue->load([
            'salesOrder.customer:id,code,name,contact_name,phone,address',
            'warehouse:id,code,name',
            'items.sku:id,sku_code,name,unit',
        ]);
        $path = $this->docxMerge->merge($goodsIssue, $template);
        $fileName = trim(preg_replace('/[^A-Za-z0-9\-_]+/', '-', $goodsIssue->code ?: 'phieu-xuat-kho'), '-') ?: 'phieu-xuat-kho';

        return response()->download($path, $fileName.'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }
}
