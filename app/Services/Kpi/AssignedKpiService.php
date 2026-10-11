<?php

namespace App\Services\Kpi;

use App\Models\GoodsIssue;
use App\Models\GoodsReceipt;
use App\Models\KpiAssignment;
use App\Models\Lead;
use App\Models\SalesOrder;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AssignedKpiService
{
    public const METRICS = [
        'tasks_completed' => ['label' => 'Công việc hoàn thành trong kỳ', 'unit' => 'việc', 'description' => 'Việc đang hoàn thành; lần chuyển hoàn thành gần nhất thuộc kỳ, tính cho người đang phụ trách.'],
        'leads_assigned' => ['label' => 'Khách tiềm năng tạo trong kỳ được giao', 'unit' => 'khách', 'description' => 'Khách tiềm năng tạo trong kỳ, theo người đang được phân công.'],
        'orders_completed_amount' => ['label' => 'Giá trị đơn bán hoàn tất trong kỳ', 'unit' => 'VND', 'description' => 'Đơn đã hoàn tất theo completed_at, tính cho sales_owner_id. Không phải tiền thực thu hay doanh thu hóa đơn.'],
        'receipts_confirmed' => ['label' => 'Phiếu nhập kho xác nhận trong kỳ', 'unit' => 'phiếu', 'description' => 'Theo confirmed_at và confirmed_by; chỉ phiếu đã xác nhận.'],
        'issues_confirmed' => ['label' => 'Phiếu xuất kho xác nhận trong kỳ', 'unit' => 'phiếu', 'description' => 'Theo confirmed_at và confirmed_by; chỉ phiếu đã xác nhận.'],
    ];

    public function audience(KpiAssignment $assignment)
    {
        $query = User::where('tenant_id', $assignment->tenant_id)->where('is_active', true);

        return match ($assignment->audience_type) {
            'user' => $query->where('id', $assignment->audience_id),
            'department' => $query->where('department_id', $assignment->audience_id),
            'position' => $query->where('position_id', $assignment->audience_id),
            default => $query,
        };
    }

    public function evaluate(KpiAssignment $assignment, User $user): array
    {
        if ($assignment->locked_at) {
            return $assignment->locked_results[(string) $user->id] ?? [];
        }
        $start = $assignment->period_start->startOfDay();
        $until = $assignment->period_end->copy()->addDay()->startOfDay();
        $tenant = $assignment->tenant_id;
        $actual = match ($assignment->metric) {
            'tasks_completed' => Task::where('tenant_id', $tenant)->where('assignee_id', $user->id)->where('status', 'completed')
                ->whereRaw('(select MAX(created_at) from task_status_histories where task_status_histories.task_id = tasks.id and to_status = ?) >= ?', ['completed', $start])
                ->whereRaw('(select MAX(created_at) from task_status_histories where task_status_histories.task_id = tasks.id and to_status = ?) < ?', ['completed', $until])->count(),
            'leads_assigned' => Lead::where('tenant_id', $tenant)->where('assigned_to', $user->id)->where('created_at', '>=', $start)->where('created_at', '<', $until)->count(),
            'orders_completed_amount' => (float) SalesOrder::where('tenant_id', $tenant)->where('sales_owner_id', $user->id)->where('status', 'completed')->where('completed_at', '>=', $start)->where('completed_at', '<', $until)->sum('total_amount'),
            'receipts_confirmed' => GoodsReceipt::where('tenant_id', $tenant)->where('confirmed_by', $user->id)->where('status', 'confirmed')->where('confirmed_at', '>=', $start)->where('confirmed_at', '<', $until)->count(),
            'issues_confirmed' => GoodsIssue::where('tenant_id', $tenant)->where('confirmed_by', $user->id)->where('status', 'confirmed')->where('confirmed_at', '>=', $start)->where('confirmed_at', '<', $until)->count(),
        };
        $target = (float) $assignment->target_value;
        $score = $assignment->direction === 'increase' ? min(100, $actual / $target * 100) : ($actual <= $target ? 100 : $target / $actual * 100);

        return ['actual' => $actual, 'score' => round($score, 2), 'name' => $assignment->definition?->name, 'code' => $assignment->definition?->code];
    }

    public function personal(User $user, string $date, ?Collection $assignments = null): array
    {
        $day = CarbonImmutable::parse($date)->toDateString();
        $all = $assignments ?? KpiAssignment::where('tenant_id', $user->tenant_id)->whereIn('status', ['active', 'locked'])
            ->whereDate('period_start', '<=', $day)->whereDate('period_end', '>=', $day)->with('definition:id,name,code')->get();
        $priority = ['company' => 1, 'department' => 2, 'position' => 3, 'user' => 4];
        $matched = $all->filter(function ($assignment) use ($user) {
            if ($assignment->locked_at) {
                return isset($assignment->locked_results[(string) $user->id]);
            }

            return match ($assignment->audience_type) {
                'user' => $assignment->audience_id === $user->id,
                'department' => $user->department_id && $assignment->audience_id === $user->department_id,
                'position' => $user->position_id && $assignment->audience_id === $user->position_id,
                default => true,
            };
        })->sortByDesc(fn ($assignment) => $priority[$assignment->audience_type])
            ->unique(fn ($assignment) => $assignment->kpi_definition_id.'-'.$assignment->period_type.'-'.$assignment->period_start->toDateString())->values();

        return $matched->map(fn ($assignment) => array_merge($this->evaluate($assignment, $user), [
            'id' => $assignment->id, 'metric' => $assignment->metric, 'metric_label' => self::METRICS[$assignment->metric]['label'],
            'description' => self::METRICS[$assignment->metric]['description'], 'unit' => self::METRICS[$assignment->metric]['unit'],
            'target' => (float) $assignment->target_value, 'weight' => (float) $assignment->weight, 'direction' => $assignment->direction,
            'period_start' => $assignment->period_start->toDateString(), 'period_end' => $assignment->period_end->toDateString(), 'period_type' => $assignment->period_type, 'status' => $assignment->status,
        ]))->all();
    }
}
