<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExecutiveDashboardService
{
    public function summary(int $tenantId, Carbon $month): array
    {
        $localMonth=Carbon::createFromFormat('Y-m-d',$month->format('Y-m').'-01','Asia/Ho_Chi_Minh')->startOfDay();
        $start = $localMonth->copy()->utc();
        $end = $localMonth->copy()->addMonth()->utc();
        $today=Carbon::today('Asia/Ho_Chi_Minh');
        $dateStart=$localMonth->toDateString();$dateEnd=$localMonth->copy()->endOfMonth()->toDateString();
        $invoices = DB::table('sales_invoices')->where('tenant_id', $tenantId)->whereNotIn('status', ['draft', 'cancelled']);
        $revenue = (float) (clone $invoices)->whereBetween('invoice_date', [$dateStart,$dateEnd])->sum('total_amount');
        $previousRevenue = (float) (clone $invoices)->whereBetween('invoice_date', [$localMonth->copy()->subMonth()->toDateString(),$localMonth->copy()->subMonth()->endOfMonth()->toDateString()])->sum('total_amount');

        $months = [];
        for ($i = 9; $i >= 0; $i--) {
            $chartMonth = $localMonth->copy()->subMonths($i);
            $months[] = [
                'label' => $chartMonth->format('m/Y'),
                'revenue' => (float) (clone $invoices)->whereBetween('invoice_date', [
                    $chartMonth->copy()->startOfMonth()->toDateString(),
                    $chartMonth->copy()->endOfMonth()->toDateString(),
                ])->sum('total_amount'),
            ];
        }

        $funnel = [
            'leads' => DB::table('leads')->where('tenant_id', $tenantId)->where('created_at','>=',$start)->where('created_at','<',$end)->count(),
            'quotations' => DB::table('quotations')->where('tenant_id', $tenantId)->where('created_at','>=',$start)->where('created_at','<',$end)->count(),
            'orders' => DB::table('sales_orders')->where('tenant_id', $tenantId)->where('created_at','>=',$start)->where('created_at','<',$end)->count(),
            'deliveries' => DB::table('deliveries')->where('tenant_id', $tenantId)->where('delivered_at','>=',$start)->where('delivered_at','<',$end)->count(),
            'payments' => DB::table('customer_payments')->where('tenant_id', $tenantId)->where('paid_at','>=',$start)->where('paid_at','<',$end)->count(),
        ];

        $receivables = DB::table('sales_invoices')->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['draft', 'cancelled'])->where('balance_amount', '>', 0);
        $totalReceivables = (float) (clone $receivables)->sum('balance_amount');
        $overdueReceivables = (float) (clone $receivables)->where('due_date', '<', $today->toDateString())->sum('balance_amount');
        $lowStock = DB::table('inventory_balances as b')->join('skus as s', 's.id', '=', 'b.sku_id')
            ->where('b.tenant_id', $tenantId)->whereColumn('b.available', '<=', 's.min_stock');

        $topCustomers = DB::table('sales_invoices as i')
            ->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->where('i.tenant_id', $tenantId)
            ->whereNotIn('i.status', ['draft', 'cancelled'])
            ->whereBetween('i.invoice_date', [$dateStart,$dateEnd])
            ->select('c.id', 'c.name')
            ->selectRaw('SUM(i.total_amount) as revenue')
            ->groupBy('c.id', 'c.name')->orderByDesc('revenue')->limit(5)->get();

        $lowStockItems = (clone $lowStock)->select('s.sku_code', 's.name', 'b.available', 's.min_stock')
            ->orderBy('b.available')->limit(6)->get();
        $aging = ['not_due' => 0.0, 'days_1_30' => 0.0, 'days_31_60' => 0.0, 'over_60' => 0.0];
        foreach ((clone $receivables)->select('due_date', 'balance_amount')->get() as $invoice) {
            $days = $invoice->due_date ? Carbon::parse($invoice->due_date,'Asia/Ho_Chi_Minh')->startOfDay()->diffInDays($today, false) : 0;
            $bucket = $days <= 0 ? 'not_due' : ($days <= 30 ? 'days_1_30' : ($days <= 60 ? 'days_31_60' : 'over_60'));
            $aging[$bucket] += (float) $invoice->balance_amount;
        }

        $purchase = [
            'requests_pending' => DB::table('purchase_requests')->where('tenant_id', $tenantId)->whereIn('status', ['pending', 'draft'])->count(),
            'orders_pending' => DB::table('purchase_orders')->where('tenant_id', $tenantId)->whereIn('status', ['draft', 'pending'])->count(),
            'orders_late' => DB::table('purchase_orders')->where('tenant_id', $tenantId)->where('expected_delivery_date', '<', $today->toDateString())->whereNotIn('status', ['received', 'cancelled'])->count(),
            'order_value' => (float) DB::table('purchase_orders')->where('tenant_id', $tenantId)->where('created_at','>=',$start)->where('created_at','<',$end)->where('status', '!=', 'cancelled')->sum('total_amount'),
            'receipts' => DB::table('goods_receipts')->where('tenant_id', $tenantId)->where('confirmed_at','>=',$start)->where('confirmed_at','<',$end)->count(),
        ];
        $operations = [
            'open_alerts' => DB::table('alerts')->where('tenant_id', $tenantId)->where('status', 'open')->count(),
            'open_tickets' => DB::table('service_tickets')->where('tenant_id', $tenantId)->whereNotIn('status', ['closed', 'cancelled'])->count(),
            'waiting_confirmation'=>DB::table('service_tickets')->where('tenant_id',$tenantId)->where('status','resolved')->count(),
            'active_contracts' => DB::table('contracts')->where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'kpi_snapshot' => DB::table('kpi_score_snapshots')->where('tenant_id', $tenantId)->orderByDesc('snapshot_date')->orderByDesc('id')->first(['overall_score', 'snapshot_date', 'period_type']),
        ];
        $activeCustomers=DB::table('customers')->where('tenant_id',$tenantId)->whereNull('merged_into_id')->where('status','active');
        $careTasks=DB::table('tasks')->where('tenant_id',$tenantId)->where('source_type','Customer')->where('task_type','customer_follow_up')->whereIn('source_id',(clone $activeCustomers)->select('id'))->whereIn('status',['new','in_progress','overdue']);
        $ticketQueue=\App\Models\ServiceTicket::where('tenant_id',$tenantId)->whereNotIn('status',['resolved','closed','cancelled'])
            ->whereDoesntHave('worklogs',fn($paused)=>$paused->where('action','sla_paused')->whereNotExists(fn($resumed)=>$resumed->selectRaw('1')->from('ticket_worklogs as resumed')->whereColumn('resumed.service_ticket_id','ticket_worklogs.service_ticket_id')->where('resumed.action','sla_resumed')->whereColumn('resumed.id','>','ticket_worklogs.id')));
        $care=[
            'overdue_followups'=>(clone $careTasks)->where('due_at','<',now())->count(),
            'today_followups'=>(clone $careTasks)->where('due_at','>=',$today->copy()->utc())->where('due_at','<',$today->copy()->addDay()->utc())->count(),
            'without_next_action'=>(clone $activeCustomers)->whereNotIn('id',(clone $careTasks)->select('source_id'))->count(),
            'response_overdue'=>(clone $ticketQueue)->whereNull('first_responded_at')->where('response_due_at','<',now())->count(),
            'resolution_overdue'=>(clone $ticketQueue)->where('resolution_due_at','<',now())->count(),
            'waiting_confirmation'=>$operations['waiting_confirmation'],
        ];
        $activity = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.tenant_id', $tenantId)->orderByDesc('a.id')->limit(7)
            ->get(['a.entity_type', 'a.action', 'a.created_at', 'u.name as user_name']);
        $recentOrders = DB::table('sales_orders as o')->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.tenant_id', $tenantId)->orderByDesc('o.id')->limit(8)
            ->get(['o.id', 'o.code', 'o.status', 'o.total_amount', 'o.created_at', 'c.name as customer_name']);
        $orderItems = DB::table('sales_order_items as i')->join('skus as s', 's.id', '=', 'i.sku_id')
            ->whereIn('i.sales_order_id', $recentOrders->pluck('id'))
            ->select('i.sales_order_id', 'i.quantity', 's.name', 's.unit')
            ->orderBy('i.id')->get()->groupBy('sales_order_id');
        $recentOrders = $recentOrders->map(function ($order) use ($orderItems) {
            $items = $orderItems->get($order->id, collect());
            $order->items = $items->take(2)->values();
            $order->more_item_count = max(0, $items->count() - 2);

            return $order;
        });
        $overdueCustomers = DB::table('sales_invoices as i')
            ->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->where('i.tenant_id', $tenantId)->where('i.balance_amount', '>', 0)
            ->whereNotIn('i.status', ['draft', 'cancelled'])
            ->where('i.due_date', '<', $today->toDateString())
            ->select('c.id', 'c.name')->selectRaw('SUM(i.balance_amount) as balance')
            ->groupBy('c.id', 'c.name')->orderByDesc('balance')->limit(5)->get();

        return [
            'employee_work' => DB::table('users as u')
                ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
                ->leftJoin('tasks as t', function ($join) use ($tenantId) {
                    $join->on('t.assignee_id', '=', 'u.id')->where('t.tenant_id', $tenantId);
                })
                ->where('u.tenant_id', $tenantId)->where('u.is_active', true)
                ->select('u.id', 'u.name', 'd.name as department_name')
                ->selectRaw("SUM(CASE WHEN t.status IN ('new','assigned','in_progress','overdue') THEN 1 ELSE 0 END) as open_count")
                ->selectRaw("SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_count")
                ->selectRaw("SUM(CASE WHEN t.status IN ('new','assigned','in_progress','overdue') AND t.due_at < ? THEN 1 ELSE 0 END) as overdue_count", [now()])
                ->groupBy('u.id', 'u.name', 'd.name')->orderByDesc('overdue_count')->orderBy('u.name')->get(),
            'generated_at' => now()->toIso8601String(),
            'governance' => [
                'unassigned_leads' => DB::table('leads')->where('tenant_id', $tenantId)->whereNull('assigned_to')->where('status', 'new')->count(),
                'customers_without_owner' => DB::table('customers')->where('tenant_id', $tenantId)->whereNull('merged_into_id')->where('status', 'active')->whereNull('sales_owner_id')->count(),
                'organizations_without_contact' => DB::table('customers as c')->where('c.tenant_id', $tenantId)->whereNull('c.merged_into_id')->where('c.status', 'active')->where('c.customer_type', 'organization')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customer_contacts as cc')->whereColumn('cc.customer_id', 'c.id')->where('cc.is_primary', true))->count(),
                'duplicate_reviews' => DB::table('customer_duplicate_reviews')->where('tenant_id', $tenantId)->where('status', 'open')->count(),
                'sales_quotes_pending' => DB::table('quotations')->where('tenant_id', $tenantId)->where('status', 'pending_approval')->count(),
            ],
            'period' => $localMonth->format('Y-m'),
            'timezone'=>'Asia/Ho_Chi_Minh',
            'revenue' => $revenue,
            'collections'=>(float)DB::table('customer_payments')->where('tenant_id',$tenantId)->where('paid_at','>=',$start)->where('paid_at','<',$end)->sum('amount'),
            'previous_revenue' => $previousRevenue,
            'orders' => $funnel['orders'],
            'receivables' => $totalReceivables,
            'overdue_receivables' => $overdueReceivables,
            'inventory_value' => (float) DB::table('inventory_balances as b')->join('skus as s', 's.id', '=', 'b.sku_id')
                ->where('b.tenant_id', $tenantId)->selectRaw('COALESCE(SUM(b.on_hand * s.cost_price), 0) as value')->value('value'),
            'low_stock_count' => (clone $lowStock)->count(),
            'pending_delivery_count' => DB::table('sales_orders')->where('tenant_id', $tenantId)
                ->whereNotIn('status', ['cancelled', 'completed'])->where('delivery_status', '!=', 'delivered')->count(),
            'overdue_tasks' => DB::table('tasks')->where('tenant_id', $tenantId)->where('due_at', '<', now())
                ->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'monthly_revenue' => $months,
            'funnel' => $funnel,
            'top_customers' => $topCustomers,
            'low_stock_items' => $lowStockItems,
            'receivable_aging' => $aging,
            'purchase' => $purchase,
            'operations' => $operations,
            'customer_care'=>$care,
            'activity' => $activity,
            'recent_orders' => $recentOrders,
            'overdue_customers' => $overdueCustomers,
        ];
    }
}
