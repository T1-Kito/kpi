<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Task;
use App\Support\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = Quotation::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['customer:id,code,name', 'items.sku:id,sku_code,name']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $quotations = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $quotations->items(),
            'meta' => [
                'page' => $quotations->currentPage(),
                'page_size' => $quotations->perPage(),
                'total' => $quotations->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'lead_id' => ['nullable', Rule::exists('leads', 'id')->where('tenant_id', $tenantId)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku_id' => ['required', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.vat_rate' => ['nullable', 'numeric', Rule::in([0, 5, 8, 10])],
        ]);

        $quotation = $this->quotations->create($request->user(), $data);

        return response()->json(['data' => $quotation], 201);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);

        $quotation->load([
            'customer:id,code,name,contact_name,phone,email',
            'items.sku:id,sku_code,name,unit',
            'salesOwner:id,name,email',
        ]);

        $lead = $quotation->lead_id
            ? Lead::where('tenant_id', $quotation->tenant_id)->whereKey($quotation->lead_id)->first()
            : null;

        $salesOrders = SalesOrder::where('tenant_id', $quotation->tenant_id)
            ->where('quotation_id', $quotation->id)
            ->latest('id')
            ->get();

        $approvals = Approval::where('tenant_id', $quotation->tenant_id)
            ->where('source_type', 'Quotation')
            ->where('source_id', $quotation->id)
            ->latest('id')
            ->get();

        return response()->json(['data' => [
            ...$quotation->toArray(),
            'approval' => $approvals->first(),
            'approvals' => $approvals,
            'tasks' => Task::where('tenant_id', $quotation->tenant_id)
                ->where('source_type', 'Quotation')
                ->where('source_id', $quotation->id)
                ->with('assignee:id,name,email')
                ->latest('id')
                ->get(),
            'alerts' => Alert::where('tenant_id', $quotation->tenant_id)
                ->where('source_type', 'Quotation')
                ->where('source_id', $quotation->id)
                ->latest('id')
                ->get(),
            'related_documents' => array_merge(
                $lead ? [[
                    'type' => 'lead',
                    'label' => 'Khách hàng tiềm năng',
                    'code' => $lead->code,
                    'status' => $lead->status,
                    'path' => '/leads/'.$lead->id,
                ]] : [],
                $salesOrders->map(fn (SalesOrder $order) => [
                    'type' => 'salesOrder',
                    'label' => 'Đơn bán',
                    'code' => $order->code,
                    'status' => $order->status,
                    'path' => '/sales-orders/'.$order->id,
                ])->all(),
            ),
            'timeline' => [
                ['label' => 'Tạo báo giá', 'status' => 'completed', 'at' => $quotation->created_at],
                ['label' => 'Kiểm tra biên lợi nhuận', 'status' => $quotation->margin_percent < 15 ? 'pending_approval' : 'ready', 'at' => $quotation->updated_at],
                ['label' => 'Trạng thái báo giá', 'status' => $quotation->status, 'at' => $quotation->updated_at],
            ],
        ]]);
    }

    public function approve(Request $request, Quotation $quotation): JsonResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $quotation = $this->quotations->approve($quotation, $request->user(), $data['status'], $data['reason'] ?? null);

        return response()->json(['data' => $quotation]);
    }
}
