<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Delivery;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Services\Sales\SalesFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesFulfillmentController extends Controller
{
    public function __construct(private readonly SalesFulfillmentService $fulfillment)
    {
    }

    public function deliveries(Request $request): JsonResponse
    {
        $query = SalesOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereIn('delivery_status', ['pending', 'delivered'])
            ->with([
                'customer:id,code,name,contact_name,phone,address,billing_address',
                'deliveries' => fn ($delivery) => $delivery->latest('delivered_at'),
                'invoices:id,sales_order_id,code,status,total_amount,paid_amount,balance_amount',
            ]);

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('deliveries', fn ($delivery) => $delivery->where('recipient_name', 'like', "%{$search}%"));
            });
        }
        if ($status = $request->query('status')) {
            $query->where('delivery_status', $status);
        }

        return $this->paginated($query->latest('id'), $request);
    }

    public function delivery(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_if($salesOrder->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! in_array($salesOrder->delivery_status, ['pending', 'delivered'], true), 404);

        return response()->json(['data' => $salesOrder->load([
            'customer:id,code,name,contact_name,phone,email,address,billing_address',
            'items.sku:id,sku_code,name,unit',
            'deliveries.goodsIssue.warehouse:id,code,name',
            'deliveries.confirmedBy:id,name,email',
            'invoices.payments',
        ])]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $query = SalesInvoice::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with([
                'salesOrder:id,code,customer_id,total_amount,status,payment_status',
                'salesOrder.customer:id,code,name,contact_name,phone',
                'issuedBy:id,name',
            ]);

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhereHas('salesOrder', fn ($order) => $order->where('code', 'like', "%{$search}%"))
                    ->orWhereHas('salesOrder.customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"));
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('invoice_date'), $request);
    }

    public function invoice(Request $request, SalesInvoice $salesInvoice): JsonResponse
    {
        abort_if($salesInvoice->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json(['data' => $salesInvoice->load([
            'salesOrder.customer:id,code,name,contact_name,phone,email,address,billing_address,tax_code',
            'salesOrder.items.sku:id,sku_code,name,unit',
            'payments.receivedBy:id,name',
            'issuedBy:id,name,email',
        ])]);
    }

    public function payments(Request $request): JsonResponse
    {
        $query = CustomerPayment::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with([
                'invoice:id,code,total_amount,paid_amount,balance_amount,status',
                'salesOrder:id,code,customer_id,status',
                'salesOrder.customer:id,code,name,contact_name,phone',
                'receivedBy:id,name',
            ]);

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%")
                    ->orWhereHas('invoice', fn ($invoice) => $invoice->where('code', 'like', "%{$search}%"))
                    ->orWhereHas('salesOrder.customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"));
            });
        }
        if ($method = $request->query('payment_method')) {
            $query->where('payment_method', $method);
        }

        return $this->paginated($query->latest('paid_at'), $request);
    }

    public function receivables(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $today = now()->toDateString();

        $query = Customer::query()
            ->where('customers.tenant_id', $tenantId)
            ->leftJoin('sales_orders', function ($join) use ($tenantId) {
                $join->on('sales_orders.customer_id', '=', 'customers.id')
                    ->where('sales_orders.tenant_id', $tenantId);
            })
            ->leftJoin('sales_invoices', function ($join) use ($tenantId) {
                $join->on('sales_invoices.sales_order_id', '=', 'sales_orders.id')
                    ->where('sales_invoices.tenant_id', $tenantId);
            })
            ->select([
                'customers.id',
                'customers.code',
                'customers.name',
                'customers.contact_name',
                'customers.phone',
                'customers.email',
                'customers.tax_code',
                'customers.identity_number',
                'customers.credit_limit',
                'customers.status',
            ])
            ->selectRaw('COUNT(DISTINCT sales_invoices.id) as invoice_count')
            ->selectRaw('COALESCE(SUM(sales_invoices.total_amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(sales_invoices.paid_amount), 0) as paid_amount')
            ->selectRaw('COALESCE(SUM(sales_invoices.balance_amount), 0) as balance_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN sales_invoices.balance_amount > 0 AND sales_invoices.due_date IS NOT NULL AND sales_invoices.due_date < ? THEN sales_invoices.balance_amount ELSE 0 END), 0) as overdue_amount', [$today])
            ->selectRaw('SUM(CASE WHEN sales_invoices.balance_amount > 0 AND sales_invoices.due_date IS NOT NULL AND sales_invoices.due_date < ? THEN 1 ELSE 0 END) as overdue_count', [$today])
            ->groupBy([
                'customers.id',
                'customers.code',
                'customers.name',
                'customers.contact_name',
                'customers.phone',
                'customers.email',
                'customers.tax_code',
                'customers.identity_number',
                'customers.credit_limit',
                'customers.status',
            ]);

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('customers.code', 'like', "%{$search}%")
                    ->orWhere('customers.name', 'like', "%{$search}%")
                    ->orWhere('customers.contact_name', 'like', "%{$search}%")
                    ->orWhere('customers.phone', 'like', "%{$search}%")
                    ->orWhere('customers.tax_code', 'like', "%{$search}%");
            });
        }

        $pageSize = min(max((int) $request->query('page_size', 20), 1), 100);
        $rows = $query
            ->orderByDesc(DB::raw('balance_amount'))
            ->paginate($pageSize);

        $data = collect($rows->items())->map(function ($row) {
            $balance = (float) $row->balance_amount;
            $creditLimit = (float) $row->credit_limit;
            $overdue = (float) $row->overdue_amount;
            $status = 'paid';

            if ($creditLimit > 0 && $balance > $creditLimit) {
                $status = 'over_limit';
            } elseif ($overdue > 0) {
                $status = 'overdue';
            } elseif ($balance > 0) {
                $status = 'unpaid';
            }

            return [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'contact_name' => $row->contact_name,
                'phone' => $row->phone,
                'email' => $row->email,
                'tax_code' => $row->tax_code,
                'identity_number' => $row->identity_number,
                'credit_limit' => $creditLimit,
                'invoice_count' => (int) $row->invoice_count,
                'total_amount' => (float) $row->total_amount,
                'paid_amount' => (float) $row->paid_amount,
                'balance_amount' => $balance,
                'overdue_amount' => $overdue,
                'overdue_count' => (int) $row->overdue_count,
                'status' => $status,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $rows->currentPage(),
                'page_size' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function payment(Request $request, CustomerPayment $customerPayment): JsonResponse
    {
        abort_if($customerPayment->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json(['data' => $customerPayment->load([
            'invoice',
            'salesOrder.customer:id,code,name,contact_name,phone,email,address,billing_address,tax_code',
            'salesOrder.items.sku:id,sku_code,name,unit',
            'receivedBy:id,name,email',
        ])]);
    }

    public function confirmDelivery(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_if($salesOrder->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:150'],
            'recipient_phone' => ['nullable', 'string', 'max:30'],
            'delivery_address' => ['required', 'string', 'max:500'],
            'delivered_at' => ['nullable', 'date'],
            'proof_note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->fulfillment->confirmDelivery($salesOrder, $request->user(), $data)], 201);
    }

    public function issueInvoice(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        abort_if($salesOrder->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate([
            'invoice_date' => ['nullable', 'date'],
            'tax_invoice_symbol' => ['nullable', 'string', 'max:50'],
            'tax_invoice_no' => ['nullable', 'string', 'max:50'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->fulfillment->issueInvoice($salesOrder, $request->user(), $data)], 201);
    }

    public function recordPayment(Request $request, SalesInvoice $salesInvoice): JsonResponse
    {
        abort_if($salesInvoice->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', Rule::in(['bank_transfer', 'cash', 'card', 'other'])],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->fulfillment->recordPayment($salesInvoice, $request->user(), $data)], 201);
    }

    private function paginated($query, Request $request): JsonResponse
    {
        $pageSize = min(max((int) $request->query('page_size', 20), 1), 100);
        $rows = $query->paginate($pageSize);

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'page' => $rows->currentPage(),
                'page_size' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }
}
