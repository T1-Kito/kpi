<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SupplierQuotation;
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
            ->with([
                'purchaseRequest:id,code',
                'supplierQuotation:id,code,total_amount,status',
                'supplier:id,code,name',
                'items.sku:id,sku_code,name,unit',
                'receivingWarehouse:id,code,name',
                'goodsReceipt:id,purchase_order_id,code,status,warehouse_id',
            ]);

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
            'supplier_quotation_id' => ['nullable', Rule::exists('supplier_quotations', 'id')->where('tenant_id', $tenantId)],
            'purchase_request_id' => ['required_without:supplier_quotation_id', Rule::exists('purchase_requests', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['required_without:supplier_quotation_id', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'expected_delivery_date' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'delivery_terms' => ['nullable', 'string', 'max:255'],
            'warranty_terms' => ['nullable', 'string', 'max:255'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0'],
            'receiving_warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)->where('status', 'active')],
        ]);

        if (! empty($data['supplier_quotation_id'])) {
            $quotation = SupplierQuotation::with(['purchaseRequest.items', 'lines.sku'])->findOrFail($data['supplier_quotation_id']);

            return response()->json([
                'data' => $this->procurement->createPoFromSupplierQuotation($quotation, $request->user(), $data['expected_delivery_date'] ?? null, $this->poTerms($data)),
            ], 201);
        }

        $pr = PurchaseRequest::with('items.sku')->findOrFail($data['purchase_request_id']);

        return response()->json([
            'data' => $this->procurement->createPoFromPr($pr, $request->user(), (int) $data['supplier_id'], $data['expected_delivery_date'] ?? null, $this->poTerms($data)),
        ], 201);
    }

    public function configureApprovalFlow(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_if($purchaseOrder->tenant_id !== $request->user()->tenant_id, 404);
        abort_unless($request->user()->hasPermission('role.manage'), 403, 'Chỉ quản trị phân quyền được thiết lập người duyệt.');
        $data = $request->validate(['approvers' => ['required', 'array', 'min:2', 'max:10'], 'approvers.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('tenant_id', $purchaseOrder->tenant_id)->where('is_active', true)]]);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($purchaseOrder, $data) {
            $purchaseOrder = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            abort_if($purchaseOrder->status !== 'draft' || collect($purchaseOrder->approval_flow ?? [])->contains(fn ($step) => !empty($step['decided_at'])), 422, 'Không được đổi luồng đã có người duyệt.');
            $users = \App\Models\User::whereIn('id', $data['approvers'])->get()->keyBy('id');
            foreach ($users as $user) abort_unless($user->hasPermission('procurement.po.approve'), 422, 'Người được chọn chưa có quyền duyệt đơn mua.');
            $purchaseOrder->update(['approval_flow' => collect($data['approvers'])->map(fn ($id, $index) => ['user_id' => $id, 'name' => $users[$id]->name, 'final' => $index === count($data['approvers']) - 1, 'decided_at' => null])->all()]);
            return response()->json(['data' => $purchaseOrder]);
        });
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_if($purchaseOrder->tenant_id !== $request->user()->tenant_id, 404);

        $purchaseOrder->load([
            'purchaseRequest:id,code,source_type,source_id,status',
            'supplierQuotation:id,code,total_amount,status',
            'supplier:id,code,name',
            'items.sku:id,sku_code,name,unit',
            'receivingWarehouse:id,code,name',
            'goodsReceipt:id,purchase_order_id,code,status,warehouse_id',
        ]);

        return response()->json(['data' => [
            ...$purchaseOrder->toArray(),
            'can_configure_approval' => $request->user()->hasPermission('role.manage'),
            'receiving_status' => $purchaseOrder->status === 'received' || $purchaseOrder->goodsReceipt?->status === 'confirmed' ? 'received' : ($purchaseOrder->status === 'approved' ? 'waiting_receipt' : $purchaseOrder->status),
            'approval_candidates' => $request->user()->hasPermission('role.manage') ? \App\Models\User::where('tenant_id', $purchaseOrder->tenant_id)->where('is_active', true)->get()->filter(fn ($user) => $user->hasPermission('procurement.po.approve'))->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values() : [],
            'related_documents' => array_merge(
                $purchaseOrder->supplierQuotation ? [[
                    'type' => 'supplierQuotation',
                    'label' => 'Báo giá NCC',
                    'code' => $purchaseOrder->supplierQuotation->code,
                    'status' => $purchaseOrder->supplierQuotation->status,
                    'path' => '/supplier-quotations/'.$purchaseOrder->supplierQuotation->id,
                ]] : [],
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
                ['label' => $purchaseOrder->supplierQuotation ? 'Tạo đơn mua từ báo giá NCC' : 'Tạo đơn mua', 'status' => 'completed', 'at' => $purchaseOrder->created_at],
                ['label' => 'Duyệt đơn mua', 'status' => $purchaseOrder->status === 'draft' ? 'pending' : 'approved', 'at' => $purchaseOrder->updated_at],
                ['label' => 'Nhập kho', 'status' => $purchaseOrder->status === 'received' ? 'completed' : ($purchaseOrder->status === 'approved' ? 'pending' : 'waiting'), 'at' => $purchaseOrder->goodsReceipt?->confirmed_at ?? $purchaseOrder->updated_at],
            ],
            'tasks' => Task::where('tenant_id', $purchaseOrder->tenant_id)->where('source_type', 'PurchaseOrder')->where('source_id', $purchaseOrder->id)->latest('id')->get(),
            'alerts' => Alert::where('tenant_id', $purchaseOrder->tenant_id)->where('source_type', 'PurchaseOrder')->where('source_id', $purchaseOrder->id)->latest('id')->get(),
        ]]);
    }


    /** @param array<string, mixed> $data */
    private function poTerms(array $data): array
    {
        return [
            'payment_terms' => $data['payment_terms'] ?? null,
            'delivery_terms' => $data['delivery_terms'] ?? null,
            'warranty_terms' => $data['warranty_terms'] ?? null,
            'shipping_fee' => (float) ($data['shipping_fee'] ?? 0),
            'receiving_warehouse_id' => (int) $data['receiving_warehouse_id'],
        ];
    }
    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_if($purchaseOrder->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->procurement->approvePo($purchaseOrder, $request->user(), $data['reason'] ?? null)]);
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_if($purchaseOrder->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->procurement->rejectPo($purchaseOrder, $request->user(), $data['reason'])]);
    }
}
