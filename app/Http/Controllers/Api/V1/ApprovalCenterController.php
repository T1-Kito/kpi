<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\KpiAdjustment;
use App\Models\KpiException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ApprovalCenterController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $pending = collect();
        $recent = collect();

        if ($user->hasPermission('sales.margin.approve')) {
            $assigned = Approval::where('tenant_id', $user->tenant_id)->where('source_type', 'Quotation')->where('approver_id', $user->id)->pluck('source_id');
            $pending = $pending->concat($this->quotations($user->tenant_id, ['pending_approval'], true, $assigned));
            $recent = $recent->concat($this->quotations($user->tenant_id, ['approved', 'rejected'], false, $assigned));
        }
        if ($user->hasPermission('procurement.pr.approve')) {
            $pending = $pending->concat($this->requests($user->tenant_id, ['draft'], true));
            $recent = $recent->concat($this->requests($user->tenant_id, ['approved', 'rejected'], false));
        }
        if ($user->hasPermission('procurement.po.approve')) {
            $pending = $pending->concat($this->orders($user->tenant_id, ['draft'], true));
            $recent = $recent->concat($this->orders($user->tenant_id, ['approved', 'rejected'], false));
        }
        if ($user->hasPermission('kpi.lock')) {
            $pending = $pending->concat($this->kpiRows(KpiAdjustment::class, $user->tenant_id, ['pending'], true))
                ->concat($this->kpiRows(KpiException::class, $user->tenant_id, ['pending'], true));
            $recent = $recent->concat($this->kpiRows(KpiAdjustment::class, $user->tenant_id, ['approved', 'rejected'], false))
                ->concat($this->kpiRows(KpiException::class, $user->tenant_id, ['approved', 'rejected'], false));
        }

        $pending = $pending->sortByDesc('created_at')->values();
        $recent = $recent->sortByDesc('decided_at')->take(12)->values();
        $type = (string) $request->query('type', 'all');
        $needle = mb_strtolower(trim((string) $request->query('q', '')));
        $filtered = $pending->when($type !== 'all', fn (Collection $rows) => $rows->where('type', $type))
            ->when($needle !== '', fn (Collection $rows) => $rows->filter(fn (array $row) => str_contains(mb_strtolower(implode(' ', [$row['code'], $row['title'], $row['owner'], $row['summary']])), $needle)))->values();
        $today = now()->toDateString();

        return response()->json(['data' => ['pending' => $filtered, 'recent' => $recent, 'counts' => $pending->countBy('type'), 'metrics' => [
            'pending' => $pending->count(),
            'overdue' => $pending->filter(fn ($row) => $row['created_at']?->lt(now()->subDay()))->count(),
            'today' => $pending->filter(fn ($row) => $row['created_at']?->toDateString() === $today)->count(),
            'processed' => $recent->filter(fn ($row) => $row['decided_at']?->toDateString() === $today)->count(),
            'approved' => $recent->where('status', 'approved')->count(), 'rejected' => $recent->where('status', 'rejected')->count(),
        ]]]);
    }

    private function quotations(int $tenantId, array $statuses, bool $pending, Collection $assigned): Collection
    {
        return Quotation::where('tenant_id', $tenantId)->whereIn('status', $statuses)->whereIn('id', $assigned)->with(['customer:id,name', 'salesOwner:id,name'])->withCount('items')->latest('id')->limit(50)->get()->map(fn ($row) => $this->row(
            'quotation', $row, 'Duyệt báo giá biên lợi nhuận', $row->salesOwner?->name ?? '-', ($row->customer?->name ?? 'Khách hàng').' · '.number_format((float) $row->total_amount, 0, ',', '.').' đ',
            $row->items_count.' dòng · Biên lợi nhuận '.number_format((float) $row->margin_percent, 2, ',', '.').'%', (float) $row->margin_percent < 15 ? 'high' : 'normal', '/quotations?record='.$row->id, $pending
        ));
    }

    private function requests(int $tenantId, array $statuses, bool $pending): Collection
    {
        return PurchaseRequest::where('tenant_id', $tenantId)->whereIn('status', $statuses)->with('requester:id,name')->withCount('items')->latest('id')->limit(50)->get()->map(fn ($row) => $this->row(
            'purchase_request', $row, 'Duyệt nhu cầu mua hàng', $row->requester?->name ?? '-', $row->reason ?: 'Yêu cầu mua hàng',
            $row->items_count.' dòng · Nguồn '.($row->source_type ?: 'Thủ công'), $row->source_type === 'SalesOrder' ? 'high' : 'normal', '/purchase-requests?record='.$row->id, $pending
        ));
    }

    private function orders(int $tenantId, array $statuses, bool $pending): Collection
    {
        return PurchaseOrder::where('tenant_id', $tenantId)->whereIn('status', $statuses)->with(['supplier:id,name', 'purchaseRequest:id,code'])->withCount('items')->latest('id')->limit(50)->get()->map(fn ($row) => $this->row(
            'purchase_order', $row, 'Duyệt đơn mua', $row->supplier?->name ?? 'Chưa chọn nhà cung cấp', number_format((float) $row->total_amount, 0, ',', '.').' đ · '.($row->purchaseRequest?->code ?? 'Không có PR'),
            $row->items_count.' dòng · Dự kiến giao '.($row->expected_delivery_date?->format('d/m/Y') ?? 'Chưa xác định'), 'normal', '/purchase-orders?record='.$row->id, $pending
        ));
    }

    private function kpiRows(string $model, int $tenantId, array $statuses, bool $pending): Collection
    {
        $adjustment = $model === KpiAdjustment::class;
        $relations = $adjustment ? ['user:id,name', 'creator:id,name'] : ['user:id,name', 'definition:id,name'];
        return $model::where('tenant_id', $tenantId)->whereIn('status', $statuses)->with($relations)->latest('id')->limit(50)->get()->map(function ($row) use ($adjustment, $pending) {
            $code = ($adjustment ? 'KPI-ADJ-' : 'KPI-EXC-').$row->id;
            $title = $adjustment ? ($row->adjustment_type === 'bonus' ? 'Duyệt cộng điểm nhân viên' : 'Duyệt trừ điểm nhân viên') : 'Duyệt giải trình ngoại lệ KPI';
            $detail = $adjustment ? (($row->adjustment_type === 'bonus' ? '+' : '-').number_format((float) $row->points, 2, ',', '.').' điểm · Người tạo '.($row->creator?->name ?? '-')) : (($row->definition?->name ?? 'KPI').' · '.$row->period_start?->format('d/m/Y').' - '.$row->period_end?->format('d/m/Y'));
            return $this->row($adjustment ? 'kpi_adjustment' : 'kpi_exception', $row, $title, $row->user?->name ?? '-', $row->reason, $detail, $adjustment && $row->adjustment_type === 'penalty' ? 'high' : 'normal', $adjustment ? '/kpi-adjustments?record='.$row->id : '/kpi?exception='.$row->id, $pending, $code, $row->reviewed_at);
        });
    }

    private function row(string $type, object $row, string $title, string $owner, string $summary, string $detail, string $risk, string $path, bool $pending, ?string $code = null, mixed $reviewedAt = null): array
    {
        return ['type' => $type, 'id' => $row->id, 'code' => $code ?? $row->code, 'title' => $title, 'owner' => $owner, 'summary' => $summary,
            'detail' => $detail, 'risk' => $risk, 'status' => $row->status, 'created_at' => $row->created_at, 'decided_at' => $pending ? null : ($reviewedAt ?? $row->updated_at), 'path' => $path];
    }
}
