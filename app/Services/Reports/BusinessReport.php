<?php

namespace App\Services\Reports;

use App\Models\{User, SalesOrder, SalesInvoice, PurchaseOrder, InventoryBalance, InventoryTransaction};
use App\Support\DataScope;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class BusinessReport
{
    public static function exportPermission(string $type): string
    {
        return match ($type) {
            'sales','care','service','care_gaps' => 'sales.report.export',
            'invoices', 'receivables' => 'finance.report.export',
            'purchase' => 'procurement.report.export',
            'inventory', 'transactions' => 'inventory.report.export',
        };
    }
    public const TYPES = [
        'care'=>['Lịch chăm sóc khách hàng','master.view'],
        'care_gaps'=>['Khách chưa có lịch chăm sóc','master.view'],
        'service'=>['Phản hồi khách hàng và SLA','service.ticket.view'],
        'sales' => ['Đơn bán hàng', 'sales.order.view'],
        'invoices' => ['Hóa đơn bán hàng', 'finance.invoice.view'],
        'purchase' => ['Đơn mua hàng', 'procurement.po.view'],
        'inventory' => ['Tồn kho hiện tại', 'master.view'],
        'transactions' => ['Giao dịch kho', 'master.view'],
        'receivables' => ['Công nợ theo hóa đơn', 'finance.payment.view'],
    ];

    public function build(User $actor, array $filters): array
    {
        $type = $filters['type'];
        abort_unless($actor->hasPermission(self::TYPES[$type][1]), 403, 'Bạn chưa có quyền xem báo cáo này.');
        if(in_array($type,['care','service','care_gaps'],true))return app(CustomerCareReport::class)->build($actor,$filters);
        $query = match ($type) {
            'sales' => SalesOrder::with(['customer', 'salesOwner']),
            'invoices', 'receivables' => SalesInvoice::with(['salesOrder.customer', 'salesOrder.salesOwner']),
            'purchase' => PurchaseOrder::with(['supplier', 'receivingWarehouse']),
            'inventory' => InventoryBalance::with(['sku', 'warehouse']),
            'transactions' => InventoryTransaction::with(['sku', 'warehouse']),
        };
        $query->where('tenant_id', $actor->tenant_id);
        if ($type === 'sales') DataScope::owned($query, $actor, 'sales_owner_id', 'salesOwner');
        if (in_array($type, ['invoices', 'receivables'])) {
            $query->whereHas('salesOrder', function ($orders) use ($actor) {
                $orders->where('tenant_id', $actor->tenant_id);
                DataScope::owned($orders, $actor, 'sales_owner_id', 'salesOwner');
            });
        }
        if ($type === 'purchase' && $actor->dataScope() !== 'company') {
            $query->whereIn('created_by', User::where('tenant_id', $actor->tenant_id)->where(function ($users) use ($actor) {
                $users->where('id', $actor->id);
                if ($actor->dataScope() === 'department' && $actor->department_id) $users->orWhere('department_id', $actor->department_id);
            })->select('id'));
        }
        if (in_array($type, ['inventory', 'transactions'])) {
            if ($actor->dataScope() !== 'company') $query->whereHas('warehouse', fn ($warehouses) => $warehouses->where('tenant_id', $actor->tenant_id)->where('manager_id', $actor->id));
            if (! empty($filters['warehouse_id'])) $query->where('warehouse_id', $filters['warehouse_id']);
            if (! empty($filters['sku_id'])) $query->where('sku_id', $filters['sku_id']);
        }
        if ($type === 'purchase' && ! empty($filters['supplier_id'])) $query->where('supplier_id', $filters['supplier_id']);
        if ($type === 'sales') {
            if (! empty($filters['customer_id'])) $query->where('customer_id', $filters['customer_id']);
            if (! empty($filters['user_id'])) $query->where('sales_owner_id', $filters['user_id']);
        }
        if (in_array($type, ['invoices', 'receivables'])) {
            if (! empty($filters['customer_id'])) $query->whereHas('salesOrder', fn ($q) => $q->where('customer_id', $filters['customer_id']));
            if (! empty($filters['user_id'])) $query->whereHas('salesOrder', fn ($q) => $q->where('sales_owner_id', $filters['user_id']));
        }
        $dateColumn = in_array($type, ['invoices', 'receivables']) ? 'invoice_date' : 'created_at';
        if (! in_array($type, ['inventory', 'receivables'])) {
            $start = $dateColumn === 'invoice_date' ? $filters['from'] : CarbonImmutable::parse($filters['from'], 'Asia/Ho_Chi_Minh')->startOfDay()->utc();
            $until = CarbonImmutable::parse($filters['to'], 'Asia/Ho_Chi_Minh')->addDay()->startOfDay();
            $query->where($dateColumn, '>=', $start)->where($dateColumn, '<', $dateColumn === 'invoice_date' ? $until->toDateString() : $until->utc());
        }
        if ($type === 'receivables') {
            $query->where('balance_amount', '>', 0)->whereNotIn('status', ['draft', 'cancelled']);
            $today = CarbonImmutable::today('Asia/Ho_Chi_Minh')->toDateString();
            if (($filters['debt_status'] ?? '') === 'overdue') $query->whereDate('due_date', '<', $today);
            if (($filters['debt_status'] ?? '') === 'not_due') $query->where(fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '>=', $today));
        }
        if (! empty($filters['status'])) $query->where($type === 'transactions' ? 'transaction_type' : 'status', $filters['status']);
        if ($needle = trim($filters['q'] ?? '')) {
            $query->where(function ($q) use ($needle, $type) {
                if (in_array($type, ['inventory', 'transactions'])) $q->whereHas('sku', fn ($sku) => $sku->where('sku_code', 'like', "%{$needle}%")->orWhere('name', 'like', "%{$needle}%"));
                else {
                    $q->where('code', 'like', "%{$needle}%");
                    if ($type === 'sales') $q->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$needle}%"));
                    elseif ($type === 'purchase') $q->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$needle}%"));
                    else $q->orWhereHas('salesOrder.customer', fn ($c) => $c->where('name', 'like', "%{$needle}%"));
                }
            });
        }
        $records = $query->orderByDesc('id')->limit(10001)->get();
        if ($records->count() > 10000) throw ValidationException::withMessages(['from' => 'Báo cáo vượt 10.000 dòng. Chọn khoảng ngày hoặc đối tượng cụ thể hơn.']);
        $columns = match ($type) {
            'sales' => ['Mã đơn', 'Ngày tạo', 'Khách hàng', 'Nhân viên', 'Trạng thái', 'Tổng tiền (VND)'],
            'purchase' => ['Mã đơn', 'Ngày tạo', 'Nhà cung cấp', 'Kho nhận', 'Trạng thái', 'Tổng tiền (VND)'],
            'invoices', 'receivables' => ['Mã hóa đơn', 'Ngày hóa đơn', 'Khách hàng', 'Nhân viên', 'Trạng thái', 'Tổng tiền (VND)', 'Đã thu (VND)', 'Còn nợ (VND)', 'Hạn thanh toán'],
            'inventory' => ['Mã hàng', 'Tên hàng', 'Kho', 'Lô', 'Đơn vị', 'Tồn thực tế', 'Đã giữ', 'Khả dụng'],
            'transactions' => ['Mã hàng', 'Tên hàng', 'Kho', 'Ngày phát sinh', 'Loại giao dịch', 'Số lượng', 'Chứng từ nguồn', 'ID nguồn'],
        };
        $rows = $records->map(fn ($r) => match ($type) {
            'sales' => [$r->code, $r->created_at, $r->customer?->name, $r->salesOwner?->name, $this->status($r->status), (float) $r->total_amount],
            'purchase' => [$r->code, $r->created_at, $r->supplier?->name, $r->receivingWarehouse?->name, $this->status($r->status), (float) $r->total_amount],
            'invoices', 'receivables' => [$r->code, $r->invoice_date?->format('Y-m-d'), $r->salesOrder?->customer?->name, $r->salesOrder?->salesOwner?->name, $this->status($r->status), (float) $r->total_amount, (float) $r->paid_amount, (float) $r->balance_amount, $r->due_date?->format('Y-m-d')],
            'inventory' => [$r->sku?->sku_code, $r->sku?->name, $r->warehouse?->name, $r->lot_no, $r->sku?->unit, (float) $r->on_hand, (float) $r->reserved, (float) $r->available],
            'transactions' => [$r->sku?->sku_code, $r->sku?->name, $r->warehouse?->name, $r->created_at, $this->status($r->transaction_type), (float) $r->quantity, $r->source_type, (string) $r->source_id],
        })->all();
        $totals = [];
        foreach ($rows as $row) foreach ($row as $index => $value) if (is_float($value) || is_int($value)) $totals[$index] = ($totals[$index] ?? 0) + $value;
        // Inventory quantities may use different units: do not present a meaningless combined total.
        if (in_array($type, ['inventory', 'transactions'])) $totals = [];
        $options = ['parties' => [], 'users' => [], 'skus' => []];
        foreach ($records as $r) {
            $party = match ($type) { 'sales' => $r->customer, 'invoices', 'receivables' => $r->salesOrder?->customer, 'purchase' => $r->supplier, default => $r->warehouse };
            $user = $type === 'sales' ? $r->salesOwner : (in_array($type, ['invoices', 'receivables']) ? $r->salesOrder?->salesOwner : null);
            if ($party) $options['parties'][$party->id] = ['id' => $party->id, 'name' => $party->name];
            if ($user) $options['users'][$user->id] = ['id' => $user->id, 'name' => $user->name];
            if (in_array($type, ['inventory', 'transactions']) && $r->sku) $options['skus'][$r->sku->id] = ['id' => $r->sku->id, 'name' => $r->sku->sku_code.' · '.$r->sku->name];
        }
        $options = array_map(fn ($items) => array_values($items), $options);
        return ['title' => self::TYPES[$type][0], 'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'options' => $options,
            'meta' => ['count' => count($rows), 'filters' => $filters, 'generated_at' => now()->toIso8601String(), 'scope' => $actor->dataScope(), 'can_export' => $actor->hasPermission(self::exportPermission($type)),
                'basis' => in_array($type, ['inventory', 'receivables']) ? 'Số dư hiện tại tại thời điểm xuất; không phải số dư lịch sử.' : ($type === 'invoices' ? 'Lọc theo ngày hóa đơn. Tổng gồm các trạng thái đã chọn.' : 'Lọc theo ngày tạo chứng từ. Tổng gồm các trạng thái đã chọn.')]];
    }

    private function status(?string $status): string
    {
        if ($status === 'invoiced') return 'Đã phát hành hóa đơn';
        if ($status === 'stock_take_adjustment') return 'Điều chỉnh kiểm kê';
        return ['draft' => 'Nháp', 'confirmed' => 'Đã xác nhận', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối', 'cancelled' => 'Đã hủy', 'completed' => 'Hoàn thành', 'received' => 'Đã nhập kho', 'issued' => 'Đã phát hành', 'paid' => 'Đã thanh toán', 'partially_paid' => 'Thanh toán một phần', 'awaiting_delivery' => 'Chờ giao', 'delivered' => 'Đã giao', 'receipt' => 'Nhập kho', 'issue' => 'Xuất kho', 'reservation' => 'Giữ hàng', 'release' => 'Bỏ giữ hàng', 'adjustment' => 'Điều chỉnh'][$status] ?? ($status ?? '');
    }
}
