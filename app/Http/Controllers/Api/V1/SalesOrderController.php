<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\GoodsIssue;
use App\Models\Delivery;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Task;
use App\Support\DataScope;
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
            ->with([
                'customer:id,code,name,contact_name,phone,email,address,billing_address,credit_limit',
                'quotation:id,code,payment_terms,status',
                'items.sku:id,sku_code,name,unit',
                'deliveries:id,sales_order_id,code,status,delivered_at',
                'invoices:id,sales_order_id,code,status,total_amount,paid_amount,balance_amount,due_date',
            ]);
        DataScope::owned($query, $request->user(), 'sales_owner_id', 'salesOwner');

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

        $quotationQuery = Quotation::with('items')
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereKey($data['quotation_id']);
        DataScope::owned($quotationQuery, $request->user(), 'sales_owner_id', 'salesOwner');
        $quotation = $quotationQuery->firstOrFail();
        $order = $this->orders->createFromQuotation($quotation, $request->user());

        return response()->json(['data' => $order], 201);
    }

    public function show(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_if($salesOrder->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(SalesOrder::whereKey($salesOrder->id), $request->user(), 'sales_owner_id', 'salesOwner')->exists(), 404);

        return response()->json(['data' => $this->withDetailContext(
            $salesOrder->load([
                'customer:id,code,name,contact_name,phone,email,address,billing_address,credit_limit',
                'quotation:id,code,payment_terms,status',
                'items.sku:id,sku_code,name,unit',
                'deliveries',
                'invoices.payments',
            ])
        )]);
    }

    public function confirm(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_if($salesOrder->tenant_id !== $request->user()->tenant_id, 404);

        $order = $this->orders->confirm($salesOrder->load(['items', 'customer']), $request->user())
            ->load('customer:id,code,name,contact_name,phone,email,credit_limit');
        $creditWarning = $this->buildCreditWarning($order);

        if ($creditWarning) {
            Alert::firstOrCreate(
                [
                    'tenant_id' => $order->tenant_id,
                    'alert_type' => $creditWarning['type'],
                    'source_type' => 'SalesOrder',
                    'source_id' => $order->id,
                ],
                [
                    'recipient_id' => $request->user()->id,
                    'level' => $creditWarning['level'],
                    'title' => $creditWarning['title'],
                    'message' => $creditWarning['message'],
                    'action_url' => '/customer-receivables',
                    'status' => 'open',
                ],
            );
        }

        return response()->json([
            'data' => $order,
            'meta' => [
                'warnings' => array_values(array_filter([$creditWarning])),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function withDetailContext(SalesOrder $order): array
    {
        $quotation = $order->quotation_id
            ? Quotation::where('tenant_id', $order->tenant_id)->whereKey($order->quotation_id)->first()
            : null;

        return [
            ...$order->toArray(),
            'payment_terms' => $order->payment_terms ?: $quotation?->payment_terms,
            'quotation' => $quotation ? [
                'id' => $quotation->id,
                'code' => $quotation->code,
                'status' => $quotation->status,
                'payment_terms' => $quotation->payment_terms,
            ] : null,
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
                Delivery::where('tenant_id', $order->tenant_id)
                    ->where('sales_order_id', $order->id)
                    ->latest('id')
                    ->get()
                    ->map(fn ($delivery) => [
                        'type' => 'delivery',
                        'label' => 'Giao hàng',
                        'code' => $delivery->code,
                        'status' => $delivery->status,
                    ])
                    ->all(),
                SalesInvoice::where('tenant_id', $order->tenant_id)
                    ->where('sales_order_id', $order->id)
                    ->latest('id')
                    ->get()
                    ->map(fn ($invoice) => [
                        'type' => 'salesInvoice',
                        'label' => 'Hóa đơn bán hàng',
                        'code' => $invoice->code,
                        'status' => $invoice->status,
                    ])
                    ->all(),
            ),
            'timeline' => [
                ['label' => 'Tạo đơn bán', 'status' => 'completed', 'at' => $order->created_at],
                ['label' => 'Kiểm tra tồn kho', 'status' => $order->stock_status, 'at' => $order->updated_at],
                ['label' => 'Xuất kho', 'status' => $order->delivery_status === 'not_ready' ? 'pending' : 'completed', 'at' => $order->updated_at],
                ['label' => 'Giao hàng', 'status' => $order->delivery_status, 'at' => $order->delivered_at],
                ['label' => 'Thanh toán', 'status' => $order->payment_status, 'at' => $order->completed_at ?? $order->updated_at],
            ],
            'tasks' => Task::where('tenant_id', $order->tenant_id)->where('source_type', 'SalesOrder')->where('source_id', $order->id)->latest('id')->get(),
            'alerts' => Alert::where('tenant_id', $order->tenant_id)->where('source_type', 'SalesOrder')->where('source_id', $order->id)->latest('id')->get(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function buildCreditWarning(SalesOrder $order): ?array
    {
        if (! $order->customer_id) {
            return null;
        }

        $summary = SalesInvoice::query()
            ->where('tenant_id', $order->tenant_id)
            ->whereHas('salesOrder', fn ($query) => $query->where('customer_id', $order->customer_id))
            ->selectRaw('COALESCE(SUM(balance_amount), 0) as balance_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance_amount > 0 AND due_date IS NOT NULL AND due_date < ? THEN balance_amount ELSE 0 END), 0) as overdue_amount', [now()->toDateString()])
            ->first();

        $balance = (float) ($summary?->balance_amount ?? 0);
        $overdue = (float) ($summary?->overdue_amount ?? 0);
        $creditLimit = (float) ($order->customer?->credit_limit ?? 0);
        $projected = $balance + (float) $order->total_amount;
        $customerName = $order->customer?->name ?: 'Khách hàng';

        if ($creditLimit > 0 && $projected > $creditLimit) {
            return [
                'type' => 'customer_credit_over_limit',
                'level' => 'high',
                'title' => 'Khách hàng vượt hạn mức công nợ',
                'message' => "{$customerName} dự kiến vượt hạn mức sau đơn {$order->code}.",
                'customer_id' => $order->customer_id,
                'credit_limit' => $creditLimit,
                'current_balance' => $balance,
                'order_total' => (float) $order->total_amount,
                'projected_balance' => $projected,
            ];
        }

        if ($overdue > 0) {
            return [
                'type' => 'customer_overdue_debt',
                'level' => 'medium',
                'title' => 'Khách hàng có công nợ quá hạn',
                'message' => "{$customerName} đang có công nợ quá hạn trước khi xử lý đơn {$order->code}.",
                'customer_id' => $order->customer_id,
                'current_balance' => $balance,
                'overdue_amount' => $overdue,
                'order_total' => (float) $order->total_amount,
            ];
        }

        return null;
    }
}
