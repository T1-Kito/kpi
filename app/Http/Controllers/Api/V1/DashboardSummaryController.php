<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\GoodsIssue;
use App\Models\GoodsReceipt;
use App\Models\InventoryBalance;
use App\Models\Lead;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Task;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardSummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', 'admin');
        $data = [
            'tasks' => $this->tasks($request),
            'alerts' => $this->alerts($request),
            'leads' => $this->blank(),
            'quotations' => $this->blank(),
            'pendingQuotes' => $this->blank(),
            'salesOrders' => $this->blank(),
            'balances' => $this->blank(),
            'purchaseRequests' => $this->blank(),
            'purchaseOrders' => $this->blank(),
            'receipts' => $this->blank(),
            'issues' => $this->blank(),
            'invoices' => $this->blank(),
            'receivables' => $this->blank(),
            'notifications' => $this->blank(),
        ];

        if (in_array($type, ['admin', 'director', 'manager'], true)) {
            $data['leads'] = $this->leads($request);
            $data['quotations'] = $this->quotations($request);
            $data['pendingQuotes'] = $this->quotations($request, 'pending_approval', 5);
            $data['salesOrders'] = $this->salesOrders($request);
            $data['purchaseRequests'] = $this->purchaseRequests($request);
            $data['purchaseOrders'] = $this->purchaseOrders($request);
            $data['balances'] = $this->balances($request);
        } elseif ($type === 'warehouse') {
            $data['balances'] = $this->balances($request, 50);
            $data['receipts'] = $this->receipts($request);
            $data['issues'] = $this->issues($request);
        } elseif ($type === 'procurement') {
            $data['purchaseRequests'] = $this->purchaseRequests($request, 50);
            $data['purchaseOrders'] = $this->purchaseOrders($request, 50);
            $data['receipts'] = $this->receipts($request);
        } elseif ($type === 'finance') {
            $data['salesOrders'] = $this->salesOrders($request);
            $data['invoices'] = $this->invoices($request, 50);
            $data['receivables'] = $this->receivables($request, 50);
        } else {
            $data['leads'] = $this->leads($request, 30);
            $data['quotations'] = $this->quotations($request, null, 30);
            $data['pendingQuotes'] = $this->quotations($request, 'pending_approval', 10);
            $data['salesOrders'] = $this->salesOrders($request, 30);
        }

        return response()->json(['data' => $data]);
    }

    private function tasks(Request $request): array
    {
        $query = Task::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('assignee_id', $request->user()->id)
            ->with(['assignee:id,name,email', 'department:id,code,name']);
        DataScope::task($query, $request->user());

        return $this->page($query
            ->orderByRaw("case when status = 'overdue' then 0 else 1 end")
            ->orderBy('due_at')
            ->latest('id'), 25);
    }

    private function alerts(Request $request): array
    {
        $query = Alert::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('recipient_id', $request->user()->id)
            ->where('status', 'open')
            ->with('recipient:id,name,email');
        DataScope::alert($query, $request->user());

        return $this->page($query->latest('id'), 10);
    }

    private function leads(Request $request, int $pageSize = 20): array
    {
        $query = Lead::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('assignee:id,name,email');
        DataScope::owned($query, $request->user(), 'assigned_to', 'assignee');

        return $this->page($query->latest('id'), $pageSize);
    }

    private function quotations(Request $request, ?string $status = null, int $pageSize = 20): array
    {
        $query = Quotation::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with([
                'customer:id,code,name,contact_name,phone,email,address,billing_address,tax_code,credit_limit',
                'items.sku:id,sku_code,name,unit',
                'salesOwner:id,name,email',
            ]);
        DataScope::owned($query, $request->user(), 'sales_owner_id', 'salesOwner');

        if ($status) {
            $query->where('status', $status);
        }

        return $this->page($query->latest('id'), $pageSize);
    }

    private function salesOrders(Request $request, int $pageSize = 20): array
    {
        $query = SalesOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with([
                'customer:id,code,name,contact_name,phone,email,address,billing_address,credit_limit',
                'items.sku:id,sku_code,name,unit',
                'deliveries:id,sales_order_id,code,status,delivered_at',
                'invoices:id,sales_order_id,code,status,total_amount,paid_amount,balance_amount,due_date',
            ]);
        DataScope::owned($query, $request->user(), 'sales_owner_id', 'salesOwner');

        return $this->page($query->latest('id'), $pageSize);
    }

    private function balances(Request $request, int $pageSize = 30): array
    {
        $query = InventoryBalance::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['sku:id,sku_code,name,unit,min_stock,max_stock', 'warehouse:id,code,name']);
        DataScope::warehouseScope($query, $request->user());

        return $this->page($query->latest('id'), $pageSize);
    }

    private function purchaseRequests(Request $request, int $pageSize = 20): array
    {
        $query = PurchaseRequest::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['items.sku:id,sku_code,name,unit', 'requester:id,name']);

        return $this->page($query->latest('id'), $pageSize);
    }

    private function purchaseOrders(Request $request, int $pageSize = 20): array
    {
        $query = PurchaseOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['purchaseRequest:id,code', 'supplier:id,code,name', 'items.sku:id,sku_code,name,unit']);

        return $this->page($query->latest('id'), $pageSize);
    }

    private function receipts(Request $request, int $pageSize = 20): array
    {
        $query = GoodsReceipt::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['purchaseOrder:id,code', 'warehouse:id,code,name', 'items.sku:id,sku_code,name,unit']);
        DataScope::warehouseScope($query, $request->user());

        return $this->page($query->latest('id'), $pageSize);
    }

    private function issues(Request $request, int $pageSize = 20): array
    {
        $query = GoodsIssue::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['salesOrder:id,code', 'warehouse:id,code,name', 'items.sku:id,sku_code,name,unit']);
        DataScope::warehouseScope($query, $request->user());

        return $this->page($query->latest('id'), $pageSize);
    }

    private function invoices(Request $request, int $pageSize = 50): array
    {
        $query = SalesInvoice::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with([
                'salesOrder:id,code,customer_id,total_amount,status,payment_status',
                'salesOrder.customer:id,code,name,contact_name,phone',
                'issuedBy:id,name',
            ]);

        return $this->page($query->latest('invoice_date'), $pageSize);
    }

    private function receivables(Request $request, int $pageSize = 50): array
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
                'customers.credit_limit',
                'customers.status',
            ])
            ->orderByDesc(DB::raw('balance_amount'));

        $rows = $query->paginate($pageSize);
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
                ...((array) $row->only(['id', 'code', 'name', 'contact_name', 'phone', 'email', 'tax_code'])),
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

        return [
            'data' => $data,
            'meta' => ['page' => $rows->currentPage(), 'page_size' => $rows->perPage(), 'total' => $rows->total()],
        ];
    }

    private function page(Builder $query, int $pageSize): array
    {
        $rows = $query->paginate($pageSize);

        return [
            'data' => $rows->items(),
            'meta' => ['page' => $rows->currentPage(), 'page_size' => $rows->perPage(), 'total' => $rows->total()],
        ];
    }

    private function blank(): array
    {
        return ['data' => [], 'meta' => ['page' => 1, 'page_size' => 0, 'total' => 0]];
    }
}
