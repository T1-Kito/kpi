<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KpiAdjustment;
use App\Models\KpiAssignment;
use App\Models\KpiPeriodClosure;
use App\Models\KpiPeriodAcknowledgement;
use App\Models\Tenant;
use App\Services\Kpi\AssignedKpiService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KpiPeriodClosureController extends Controller
{
    public function __construct(private AssignedKpiService $service, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        return response()->json(['data' => KpiPeriodClosure::where('tenant_id', $request->user()->tenant_id)
            ->with(['bundle:id,code,name,version', 'acknowledgements.user:id,name'])->latest('period_start')->latest('revision')->get()]);
    }

    public function mine(Request $request)
    {
        return response()->json(['data' => KpiPeriodAcknowledgement::where('user_id', $request->user()->id)
            ->whereHas('closure', fn ($query) => $query->where('tenant_id', $request->user()->tenant_id))
            ->with(['closure.bundle:id,code,name,version'])->latest('id')->get()]);
    }

    public function close(Request $request)
    {
        $data = $request->validate(['assignment_id' => ['required', 'integer']]);
        $tenantId = $request->user()->tenant_id;

        return DB::transaction(function () use ($data, $request, $tenantId) {
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $anchor = KpiAssignment::where('tenant_id', $tenantId)->whereKey($data['assignment_id'])->lockForUpdate()->firstOrFail();
            abort_if(! $anchor->kpi_bundle_id, 422, 'Chỉ có thể chốt đồng thời KPI được giao theo bộ.');
            $assignments = $this->groupQuery($anchor)->lockForUpdate()->with('definition:id,code,name')->get();
            abort_if($assignments->isEmpty(), 422, 'Không tìm thấy KPI trong kỳ.');
            abort_if($assignments->contains(fn ($row) => $row->locked_at), 422, 'Kỳ KPI đã được chốt.');
            abort_if($assignments->contains(fn ($row) => $row->status !== 'active'), 422, 'Tất cả chỉ tiêu phải ở trạng thái đang theo dõi.');
            abort_if($anchor->period_end->endOfDay()->isFuture(), 422, 'Kỳ chưa kết thúc, chưa thể chốt kết quả.');

            $members = $this->service->audience($anchor)->orderBy('id')->get();
            abort_if($members->isEmpty(), 422, 'Nhóm không còn nhân viên hoạt động; cần xử lý dữ liệu nền trước khi chốt.');
            $pending = KpiAdjustment::where('tenant_id', $tenantId)->whereIn('user_id', $members->pluck('id'))
                ->where('status', 'pending')->whereDate('period_start', '<=', $anchor->period_end)
                ->whereDate('period_end', '>=', $anchor->period_start)->count();
            if ($pending) {
                throw ValidationException::withMessages(['period' => "Còn {$pending} điều chỉnh KPI đang chờ duyệt trong kỳ."]);
            }

            $snapshot = ['members' => $members->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()->all(), 'assignments' => []];
            foreach ($assignments as $assignment) {
                $results = [];
                foreach ($members as $user) $results[(string) $user->id] = $this->service->evaluate($assignment, $user);
                $before = $assignment->toArray();
                $assignment->update(['locked_results' => $results, 'locked_at' => now(), 'status' => 'locked']);
                $snapshot['assignments'][] = ['id' => $assignment->id, 'definition' => $assignment->definition?->only(['id', 'code', 'name']), 'target' => (float) $assignment->target_value, 'weight' => (float) $assignment->weight, 'results' => $results];
                $this->audit->record('kpi_assignment', $assignment->id, 'lock_assignment_by_period', $request->user(), $before, $assignment->toArray(), $request);
            }
            $revision = (int) KpiPeriodClosure::where('tenant_id', $tenantId)->where('kpi_bundle_id', $anchor->kpi_bundle_id)
                ->where('audience_type', $anchor->audience_type)->where('audience_id', $anchor->audience_id)
                ->where('period_type', $anchor->period_type)->whereDate('period_start', $anchor->period_start)->max('revision') + 1;
            $closure = KpiPeriodClosure::create([
                'tenant_id' => $tenantId, 'kpi_bundle_id' => $anchor->kpi_bundle_id, 'audience_type' => $anchor->audience_type,
                'audience_id' => $anchor->audience_id, 'period_type' => $anchor->period_type, 'period_start' => $anchor->period_start,
                'period_end' => $anchor->period_end, 'revision' => $revision, 'status' => 'employee_review', 'member_ids' => $members->pluck('id')->all(),
                'snapshot' => $snapshot, 'closed_by' => $request->user()->id, 'closed_at' => now(),
            ]);
            foreach ($members as $member) $closure->acknowledgements()->create(['user_id' => $member->id, 'status' => 'pending']);
            $this->audit->record('kpi_period_closure', $closure->id, 'close_period', $request->user(), null, $closure->toArray(), $request);
            return response()->json(['data' => $closure->load(['bundle:id,code,name,version', 'acknowledgements.user:id,name'])], 201);
        });
    }

    public function respond(Request $request, KpiPeriodClosure $kpiPeriodClosure)
    {
        abort_if($kpiPeriodClosure->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['action' => ['required', 'in:acknowledge,dispute'], 'note' => ['nullable', 'string', 'max:2000']]);
        if ($data['action'] === 'dispute' && mb_strlen(trim($data['note'] ?? '')) < 10) {
            throw ValidationException::withMessages(['note' => 'Phản hồi sai lệch cần mô tả ít nhất 10 ký tự.']);
        }
        return DB::transaction(function () use ($request, $kpiPeriodClosure, $data) {
            $closure = KpiPeriodClosure::whereKey($kpiPeriodClosure->id)->lockForUpdate()->firstOrFail();
            abort_if($closure->status !== 'employee_review', 422, 'Kỳ này không còn ở bước nhân viên xác nhận.');
            $ack = KpiPeriodAcknowledgement::where('kpi_period_closure_id', $closure->id)->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($ack->status !== 'pending', 422, 'Bạn đã gửi xác nhận cho kỳ này.');
            $before = $ack->toArray();
            $ack->update(['status' => $data['action'] === 'acknowledge' ? 'acknowledged' : 'disputed', 'employee_note' => trim($data['note'] ?? '') ?: null, 'responded_at' => now()]);
            $this->audit->record('kpi_period_acknowledgement', $ack->id, $data['action'], $request->user(), $before, $ack->toArray(), $request);
            return response()->json(['data' => $ack->fresh()->load('closure.bundle:id,code,name,version')]);
        });
    }

    public function resolve(Request $request, KpiPeriodAcknowledgement $kpiPeriodAcknowledgement)
    {
        $ack = $kpiPeriodAcknowledgement->load('closure');
        abort_if($ack->closure->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['note' => ['required', 'string', 'min:10', 'max:2000']]);
        abort_if($ack->status !== 'disputed', 422, 'Chỉ xử lý phản hồi đang có sai lệch.');
        $before = $ack->toArray();
        $ack->update(['status' => 'resolved', 'review_note' => $data['note'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        $this->audit->record('kpi_period_acknowledgement', $ack->id, 'resolve_dispute', $request->user(), $before, $ack->toArray(), $request);
        return response()->json(['data' => $ack->fresh()->load('user:id,name')]);
    }

    public function finalize(Request $request, KpiPeriodClosure $kpiPeriodClosure)
    {
        abort_if($kpiPeriodClosure->tenant_id !== $request->user()->tenant_id, 404);
        return DB::transaction(function () use ($request, $kpiPeriodClosure) {
            $closure = KpiPeriodClosure::whereKey($kpiPeriodClosure->id)->lockForUpdate()->firstOrFail();
            abort_if($closure->status !== 'employee_review', 422, 'Kỳ này không ở bước chờ chốt cuối.');
            $unready = $closure->acknowledgements()->whereIn('status', ['pending', 'disputed'])->count();
            abort_if($unready > 0, 422, "Còn {$unready} nhân viên chưa xác nhận hoặc chưa xử lý phản hồi.");
            $before = $closure->toArray();
            $closure->update(['status' => 'closed']);
            $this->audit->record('kpi_period_closure', $closure->id, 'finalize_period', $request->user(), $before, $closure->toArray(), $request);
            return response()->json(['data' => $closure->fresh()->load(['bundle:id,code,name,version', 'acknowledgements.user:id,name'])]);
        });
    }

    public function reopen(Request $request, KpiPeriodClosure $kpiPeriodClosure)
    {
        abort_if($kpiPeriodClosure->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);

        return DB::transaction(function () use ($request, $kpiPeriodClosure, $data) {
            Tenant::whereKey($kpiPeriodClosure->tenant_id)->lockForUpdate()->firstOrFail();
            $closure = KpiPeriodClosure::whereKey($kpiPeriodClosure->id)->lockForUpdate()->firstOrFail();
            abort_if(! in_array($closure->status, ['closed', 'employee_review']), 422, 'Kỳ này không ở trạng thái có thể mở lại.');
            $newer = KpiPeriodClosure::where('tenant_id', $closure->tenant_id)->where('kpi_bundle_id', $closure->kpi_bundle_id)
                ->where('audience_type', $closure->audience_type)->where('audience_id', $closure->audience_id)
                ->where('period_type', $closure->period_type)->whereDate('period_start', $closure->period_start)
                ->where('revision', '>', $closure->revision)->exists();
            abort_if($newer, 422, 'Chỉ được mở lại phiên bản chốt mới nhất.');
            $assignments = KpiAssignment::whereIn('id', collect($closure->snapshot['assignments'] ?? [])->pluck('id'))->lockForUpdate()->get();
            abort_if($assignments->count() !== count($closure->snapshot['assignments'] ?? []), 422, 'Dữ liệu phân KPI đã thay đổi; không thể mở lại tự động.');
            foreach ($assignments as $assignment) $assignment->update(['locked_results' => null, 'locked_at' => null, 'status' => 'active']);
            $before = $closure->toArray();
            $closure->update(['status' => 'reopened', 'reopened_by' => $request->user()->id, 'reopened_at' => now(), 'reopen_reason' => $data['reason']]);
            $this->audit->record('kpi_period_closure', $closure->id, 'reopen_period', $request->user(), $before, $closure->toArray(), $request);
            return response()->json(['data' => $closure->fresh()->load('bundle:id,code,name,version')]);
        });
    }

    private function groupQuery(KpiAssignment $anchor)
    {
        return KpiAssignment::where('tenant_id', $anchor->tenant_id)->where('kpi_bundle_id', $anchor->kpi_bundle_id)
            ->where('audience_type', $anchor->audience_type)->where('audience_id', $anchor->audience_id)
            ->where('period_type', $anchor->period_type)->whereDate('period_start', $anchor->period_start);
    }
}
