<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\KpiAdjustment;
use App\Models\KpiException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ApprovalCenterController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tenant = Tenant::query()->where('status', 'active')->firstOrFail();
        $type = $request->query('type', 'all');
        $keyword = trim((string) $request->query('q', ''));

        $pending = collect()
            ->concat($this->quotationRows($tenant->id, ['pending_approval'], true))
            ->concat($this->purchaseRequestRows($tenant->id, ['draft'], true))
            ->concat($this->purchaseOrderRows($tenant->id, ['draft'], true))
            ->concat($this->adjustmentRows($tenant->id, ['pending'], true))
            ->concat($this->exceptionRows($tenant->id, ['pending'], true))
            ->sortByDesc('created_at')
            ->values();

        $recent = collect()
            ->concat($this->quotationRows($tenant->id, ['approved', 'rejected'], false))
            ->concat($this->purchaseRequestRows($tenant->id, ['approved', 'rejected'], false))
            ->concat($this->purchaseOrderRows($tenant->id, ['approved', 'rejected'], false))
            ->concat($this->adjustmentRows($tenant->id, ['approved', 'rejected'], false))
            ->concat($this->exceptionRows($tenant->id, ['approved', 'rejected'], false))
            ->sortByDesc('decided_at')
            ->take(12)
            ->values();

        $today = now()->toDateString();
        $metrics = [
            'pending' => $pending->count(),
            'overdue' => $pending->filter(fn (array $row) => $row['created_at']?->lt(now()->subDay()))->count(),
            'today' => $pending->filter(fn (array $row) => $row['created_at']?->toDateString() === $today)->count(),
            'processed' => $recent->filter(fn (array $row) => $row['decided_at']?->toDateString() === $today)->count(),
            'approved' => $recent->where('status', 'approved')->count(),
            'rejected' => $recent->where('status', 'rejected')->count(),
        ];

        $counts = $pending->countBy('type');
        $filtered = $pending
            ->when($type !== 'all', fn (Collection $rows) => $rows->where('type', $type))
            ->when($keyword !== '', function (Collection $rows) use ($keyword) {
                $needle = mb_strtolower($keyword);
                return $rows->filter(fn (array $row) => str_contains(mb_strtolower(implode(' ', [
                    $row['code'], $row['title'], $row['owner'], $row['summary'],
                ])), $needle));
            })
            ->values();

        return view('pages.approvals.index', [
            'pending' => $filtered,
            'recent' => $recent,
            'counts' => $counts,
            'totalPending' => $pending->count(),
            'type' => $type,
            'keyword' => $keyword,
            'metrics' => $metrics,
        ]);
    }

    private function quotationRows(int $tenantId, array $statuses, bool $pending): Collection
    {
        return Quotation::query()->where('tenant_id', $tenantId)->whereIn('status', $statuses)
            ->with(['customer:id,name', 'salesOwner:id,name,email'])->withCount('items')->latest('id')->limit(50)->get()
            ->map(fn (Quotation $row) => [
                'type' => 'quotation', 'id' => $row->id, 'code' => $row->code,
                'title' => 'Duyệt báo giá biên lợi nhuận', 'owner' => $row->salesOwner?->name ?? '-',
                'summary' => ($row->customer?->name ?? 'Khách hàng').' · '.number_format((float) $row->total_amount, 0, ',', '.').' đ',
                'detail' => $row->items_count.' dòng · Biên lợi nhuận '.number_format((float) $row->margin_percent, 2, ',', '.').'%',
                'risk' => (float) $row->margin_percent < 15 ? 'high' : 'normal', 'status' => $row->status,
                'created_at' => $row->created_at, 'decided_at' => $pending ? null : $row->updated_at,
                'path' => '/quotations?record='.$row->id,
            ]);
    }

    private function purchaseRequestRows(int $tenantId, array $statuses, bool $pending): Collection
    {
        return PurchaseRequest::query()->where('tenant_id', $tenantId)->whereIn('status', $statuses)
            ->with(['requester:id,name,email'])->withCount('items')->latest('id')->limit(50)->get()
            ->map(fn (PurchaseRequest $row) => [
                'type' => 'purchase_request', 'id' => $row->id, 'code' => $row->code,
                'title' => 'Duyệt nhu cầu mua hàng', 'owner' => $row->requester?->name ?? '-',
                'summary' => $row->reason ?: 'Yêu cầu mua hàng', 'detail' => $row->items_count.' dòng · Nguồn '.($row->source_type ?: 'Thủ công'),
                'risk' => $row->source_type === 'SalesOrder' ? 'high' : 'normal', 'status' => $row->status,
                'created_at' => $row->created_at, 'decided_at' => $pending ? null : $row->updated_at,
                'path' => '/purchase-requests?record='.$row->id,
            ]);
    }

    private function purchaseOrderRows(int $tenantId, array $statuses, bool $pending): Collection
    {
        return PurchaseOrder::query()->where('tenant_id', $tenantId)->whereIn('status', $statuses)
            ->with(['supplier:id,name', 'purchaseRequest:id,code'])->withCount('items')->latest('id')->limit(50)->get()
            ->map(fn (PurchaseOrder $row) => [
                'type' => 'purchase_order', 'id' => $row->id, 'code' => $row->code,
                'title' => 'Duyệt đơn mua', 'owner' => $row->supplier?->name ?? 'Chưa chọn nhà cung cấp',
                'summary' => number_format((float) $row->total_amount, 0, ',', '.').' đ · '.($row->purchaseRequest?->code ?? 'Không có PR'),
                'detail' => $row->items_count.' dòng · Dự kiến giao '.($row->expected_delivery_date?->format('d/m/Y') ?? 'Chưa xác định'),
                'risk' => 'normal', 'status' => $row->status,
                'created_at' => $row->created_at, 'decided_at' => $pending ? null : $row->updated_at,
                'path' => '/purchase-orders?record='.$row->id,
            ]);
    }

    private function adjustmentRows(int $tenantId, array $statuses, bool $pending): Collection
    {
        return KpiAdjustment::query()->where('tenant_id', $tenantId)->whereIn('status', $statuses)
            ->with(['user:id,name,email', 'creator:id,name,email'])->latest('id')->limit(50)->get()
            ->map(fn (KpiAdjustment $row) => [
                'type' => 'kpi_adjustment', 'id' => $row->id, 'code' => 'KPI-ADJ-'.$row->id,
                'title' => $row->adjustment_type === 'bonus' ? 'Duyệt cộng điểm nhân viên' : 'Duyệt trừ điểm nhân viên',
                'owner' => $row->user?->name ?? '-', 'summary' => $row->reason,
                'detail' => ($row->adjustment_type === 'bonus' ? '+' : '-').number_format((float) $row->points, 2, ',', '.').' điểm · Người tạo '.($row->creator?->name ?? '-'),
                'risk' => $row->adjustment_type === 'penalty' ? 'high' : 'normal', 'status' => $row->status,
                'created_at' => $row->created_at, 'decided_at' => $pending ? null : ($row->reviewed_at ?? $row->updated_at),
                'path' => '/kpi-adjustments?record='.$row->id,
            ]);
    }

    private function exceptionRows(int $tenantId, array $statuses, bool $pending): Collection
    {
        return KpiException::query()->where('tenant_id', $tenantId)->whereIn('status', $statuses)
            ->with(['user:id,name,email', 'definition:id,name'])->latest('id')->limit(50)->get()
            ->map(fn (KpiException $row) => [
                'type' => 'kpi_exception', 'id' => $row->id, 'code' => 'KPI-EXC-'.$row->id,
                'title' => 'Duyệt giải trình ngoại lệ KPI', 'owner' => $row->user?->name ?? '-',
                'summary' => $row->reason, 'detail' => ($row->definition?->name ?? 'KPI').' · '.$row->period_start?->format('d/m/Y').' - '.$row->period_end?->format('d/m/Y'),
                'risk' => 'normal', 'status' => $row->status,
                'created_at' => $row->created_at, 'decided_at' => $pending ? null : ($row->reviewed_at ?? $row->updated_at),
                'path' => '/kpi?exception='.$row->id,
            ]);
    }
}
