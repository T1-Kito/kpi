<?php

namespace App\Services\Kpi;

use App\Models\KpiAdjustment;
use App\Models\KpiAssignment;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeKpiReport
{
    public function build(User $actor, array $filters): array
    {
        $type = $filters['period_type'] ?? 'month';
        $date = CarbonImmutable::parse($filters['date'] ?? now()->toDateString());
        [$start, $end] = match ($type) {
            'quarter' => [$date->startOfQuarter(), $date->endOfQuarter()],
            'year' => [$date->startOfYear(), $date->endOfYear()],
            default => [$date->startOfMonth(), $date->endOfMonth()],
        };

        return DB::transaction(function () use ($actor, $filters, $type, $start, $end) {
            $users = User::where('tenant_id', $actor->tenant_id)->with(['department:id,name', 'position:id,name']);
            if ($actor->dataScope() !== 'company') {
                $users->where(function ($query) use ($actor) {
                    $query->where('id', $actor->id);
                    if ($actor->dataScope() === 'department' && $actor->department_id) {
                        $query->orWhere('department_id', $actor->department_id);
                    }
                });
            }
            $available = (clone $users)->orderBy('name')->get(['id', 'name', 'department_id', 'position_id', 'is_active']);
            if (! empty($filters['user_id'])) {
                $users->where('id', $filters['user_id']);
            }
            if (! empty($filters['department_id'])) {
                $users->where('department_id', $filters['department_id']);
            }
            $employees = $users->orderBy('name')->get(['id', 'name', 'department_id', 'position_id', 'is_active']);
            $ids = $employees->modelKeys();
            $tasks = Task::where('tenant_id', $actor->tenant_id)->whereIn('assignee_id', $ids)
                ->where('created_at', '>=', $start)->where('created_at', '<', $end->addDay()->startOfDay())
                ->orderBy('id')->limit(10001)->get(['id', 'code', 'title', 'assignee_id', 'status', 'due_at', 'created_at']);
            $points = KpiAdjustment::where('tenant_id', $actor->tenant_id)->whereIn('user_id', $ids)
                ->where('period_type', $type)->whereDate('period_start', $start->toDateString())
                ->whereDate('period_end', $end->toDateString())->orderBy('id')->limit(10001)->get();
            if ($tasks->count() > 10000 || $points->count() > 10000) {
                throw ValidationException::withMessages(['date' => 'Kỳ có hơn 10.000 bản ghi. Hãy chọn phòng ban hoặc nhân viên để xuất đầy đủ.']);
            }
            $taskGroups = $tasks->groupBy('assignee_id');
            $pointGroups = $points->groupBy('user_id');
            $asOf = now();
            $rows = $employees->map(function ($user) use ($taskGroups, $pointGroups, $asOf) {
                $work = $taskGroups->get($user->id, collect());
                $ledger = $pointGroups->get($user->id, collect());
                $approved = $ledger->where('status', 'approved');
                $bonus = round((float) $approved->where('adjustment_type', 'bonus')->sum('points'), 2);
                $penalty = round((float) $approved->where('adjustment_type', 'penalty')->sum('points'), 2);

                return ['user_id' => $user->id, 'name' => $user->name, 'department' => $user->department?->name,
                    'position' => $user->position?->name, 'is_active' => $user->is_active,
                    'tasks_created' => $work->count(), 'tasks_completed' => $work->where('status', 'completed')->count(),
                    'tasks_not_started' => $work->where('status', 'new')->count(),
                    'tasks_in_progress' => $work->where('status', 'in_progress')->count(),
                    'tasks_overdue' => $work->filter(fn ($task) => in_array($task->status, ['new', 'in_progress', 'overdue']) && $task->due_at?->lt($asOf))->count(),
                    'bonus_points' => $bonus, 'penalty_points' => $penalty, 'net_points' => round($bonus - $penalty, 2),
                    'pending_adjustments' => $ledger->where('status', 'pending')->count()];
            })->values();

            $details = [];
            $assigned = app(AssignedKpiService::class);
            $assignmentRows = KpiAssignment::where('tenant_id', $actor->tenant_id)->whereIn('status', ['active', 'locked'])
                ->where('period_type', $type)->whereDate('period_start', $start->toDateString())
                ->with('definition:id,name,code')->get();
            foreach ($employees as $index => $user) {
                $indicators = collect($assigned->personal($user, $start->toDateString(), $assignmentRows))
                    ->where('period_type', $type)->where('period_start', $start->toDateString())->values();
                $weight = $indicators->sum('weight');
                $row = $rows[$index];
                $row['kpi_indicators'] = $indicators->count();
                $row['kpi_score'] = $weight > 0 ? round($indicators->sum(fn ($indicator) => $indicator['score'] * $indicator['weight']) / $weight, 2) : null;
                $row['kpi_status'] = $indicators->isEmpty() ? 'unassigned' : ($indicators->every(fn ($indicator) => $indicator['direction'] === 'increase'
                    ? $indicator['actual'] >= $indicator['target'] : $indicator['actual'] <= $indicator['target']) ? 'achieved' : 'not_achieved');
                $row['kpi_locked'] = $indicators->isNotEmpty() && $indicators->every(fn ($indicator) => $indicator['status'] === 'locked');
                $row['kpi_details'] = $indicators->all();
                $rows[$index] = $row;
                foreach ($indicators as $indicator) {
                    $details[] = ['user_id' => $user->id, 'employee_name' => $user->name] + $indicator;
                }
            }

            return ['rows' => $rows->all(), 'employees' => $available->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'department_id' => $user->department_id, 'department' => $user->department?->name])->all(),
                'tasks' => $tasks->toArray(), 'adjustments' => $points->toArray(), 'kpis' => $details,
                'meta' => ['period_type' => $type, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                    'as_of' => $asOf->toIso8601String(), 'scope' => $actor->dataScope(),
                    'task_basis' => 'Công việc tạo trong kỳ, trạng thái hiện tại tại thời điểm xem.',
                    'points_basis' => 'Phiếu đúng kỳ; chỉ phiếu đã duyệt được cộng/trừ. Không phải điểm KPI tổng hoặc điểm chốt thưởng.']];
        });
    }
}
