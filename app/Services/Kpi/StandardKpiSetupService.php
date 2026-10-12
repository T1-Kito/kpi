<?php

namespace App\Services\Kpi;

use App\Models\Department;
use App\Models\KpiAssignment;
use App\Models\KpiBundle;
use App\Models\KpiBundlePlan;
use App\Models\KpiDefinition;
use App\Models\Position;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StandardKpiSetupService
{
    public function __construct(private KpiBundleRolloutService $rollout) {}

    public function setup(User $actor, CarbonImmutable $date): array
    {
        return DB::transaction(function () use ($actor, $date) {
            $tenantId = (int) $actor->tenant_id;
            $definitions = $this->definitions($tenantId);
            $createdAssignments = 0;
            $configured = [];
            $skipped = [];

            foreach ($this->blueprints() as $blueprint) {
                $audience = $this->findOrCreateAudience($tenantId, $blueprint['audience'], $blueprint['matches'], $blueprint['code']);
                $bundle = KpiBundle::firstOrCreate(
                    ['tenant_id' => $tenantId, 'code' => $blueprint['code'], 'version' => 1],
                    ['name' => $blueprint['name'], 'description' => $blueprint['description'], 'status' => 'active', 'created_by' => $actor->id, 'published_at' => now()],
                );
                if ($bundle->wasRecentlyCreated) {
                    foreach ($blueprint['items'] as $index => $item) {
                        $bundle->items()->create([
                            'kpi_definition_id' => $definitions[$item['metric']]->id,
                            'metric' => $item['metric'], 'target_value' => $item['target'],
                            'weight' => $item['weight'], 'direction' => 'increase', 'sort_order' => $index + 1,
                        ]);
                    }
                }
                $plan = KpiBundlePlan::updateOrCreate(
                    ['tenant_id' => $tenantId, 'kpi_bundle_id' => $bundle->id, 'audience_type' => $blueprint['audience'], 'audience_id' => $audience->id, 'period_type' => 'month'],
                    ['assignment_status' => 'active', 'starts_on' => $date->startOfMonth(), 'status' => 'active', 'created_by' => $actor->id],
                );
                $createdAssignments += $this->rollout->ensure($plan, $date);
                $configured[] = ['bundle' => $bundle->code, 'name' => $bundle->name, 'audience' => $audience->name,
                    'employees' => $this->employeeCount($tenantId, $blueprint['audience'], $audience->id), 'indicators' => count($blueprint['items'])];
            }

            return ['configured' => $configured, 'skipped' => $skipped, 'created_assignments' => $createdAssignments,
                'active_assignments' => KpiAssignment::where('tenant_id', $tenantId)->where('status', 'active')->count()];
        });
    }

    private function definitions(int $tenantId): array
    {
        $specs = [
            'tasks_completed' => ['STD-TASKS', 'Công việc hoàn thành', 'việc'],
            'leads_assigned' => ['STD-LEADS', 'Khách tiềm năng được giao', 'khách'],
            'orders_completed_amount' => ['STD-SALES-AMOUNT', 'Giá trị đơn bán hoàn tất', 'VND'],
            'receipts_confirmed' => ['STD-RECEIPTS', 'Phiếu nhập kho xác nhận', 'phiếu'],
            'issues_confirmed' => ['STD-ISSUES', 'Phiếu xuất kho xác nhận', 'phiếu'],
            'purchase_orders_created' => ['STD-PURCHASE-ORDERS', 'Đơn mua đã lập', 'đơn'],
            'payments_received_amount' => ['STD-PAYMENTS', 'Giá trị thu tiền', 'VND'],
            'tickets_resolved' => ['STD-TICKETS', 'Ticket đã xử lý', 'ticket'],
        ];
        $rows = [];
        foreach ($specs as $metric => [$code, $name, $unit]) {
            $rows[$metric] = KpiDefinition::updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $code],
                ['name' => $name, 'source_type' => 'automatic', 'formula' => $metric, 'unit' => $unit, 'target_direction' => 'increase', 'weight' => 0, 'status' => 'active'],
            );
        }
        return $rows;
    }

    private function blueprints(): array
    {
        return [
            ['code' => 'STD-SALES', 'name' => 'KPI Kinh doanh chuẩn', 'description' => 'Khách tiềm năng, doanh số hoàn tất và kỷ luật công việc.', 'audience' => 'department', 'matches' => ['kinh doanh', 'sales'], 'items' => [
                ['metric' => 'leads_assigned', 'target' => 20, 'weight' => 25], ['metric' => 'orders_completed_amount', 'target' => 100000000, 'weight' => 55], ['metric' => 'tasks_completed', 'target' => 30, 'weight' => 20],
            ]],
            ['code' => 'STD-WAREHOUSE', 'name' => 'KPI Kho vận chuẩn', 'description' => 'Nhập kho, xuất kho và công việc vận hành.', 'audience' => 'department', 'matches' => ['kho', 'warehouse'], 'items' => [
                ['metric' => 'receipts_confirmed', 'target' => 30, 'weight' => 35], ['metric' => 'issues_confirmed', 'target' => 30, 'weight' => 45], ['metric' => 'tasks_completed', 'target' => 30, 'weight' => 20],
            ]],
            ['code' => 'STD-PROCUREMENT', 'name' => 'KPI Mua hàng chuẩn', 'description' => 'Đơn mua đã lập và công việc mua hàng hoàn tất.', 'audience' => 'department', 'matches' => ['mua hang', 'procurement'], 'items' => [
                ['metric' => 'purchase_orders_created', 'target' => 20, 'weight' => 70], ['metric' => 'tasks_completed', 'target' => 30, 'weight' => 30],
            ]],
            ['code' => 'STD-FINANCE', 'name' => 'KPI Tài chính chuẩn', 'description' => 'Giá trị thu tiền và công việc tài chính.', 'audience' => 'department', 'matches' => ['tai chinh', 'finance'], 'items' => [
                ['metric' => 'payments_received_amount', 'target' => 100000000, 'weight' => 75], ['metric' => 'tasks_completed', 'target' => 30, 'weight' => 25],
            ]],
            ['code' => 'STD-MARKETING', 'name' => 'KPI Marketing chuẩn', 'description' => 'Khách tiềm năng được giao và công việc chiến dịch.', 'audience' => 'department', 'matches' => ['marketing'], 'items' => [
                ['metric' => 'leads_assigned', 'target' => 30, 'weight' => 70], ['metric' => 'tasks_completed', 'target' => 30, 'weight' => 30],
            ]],
            ['code' => 'STD-SERVICE', 'name' => 'KPI Chăm sóc khách hàng chuẩn', 'description' => 'Ticket đã xử lý và công việc chăm sóc khách hàng.', 'audience' => 'department', 'matches' => ['cham soc khach hang', 'dich vu', 'service'], 'items' => [
                ['metric' => 'tickets_resolved', 'target' => 30, 'weight' => 75], ['metric' => 'tasks_completed', 'target' => 30, 'weight' => 25],
            ]],
            ['code' => 'STD-MANAGER', 'name' => 'KPI Trưởng phòng chuẩn', 'description' => 'Kỷ luật xử lý công việc của trưởng phòng.', 'audience' => 'position', 'matches' => ['truong phong', 'manager'], 'items' => [
                ['metric' => 'tasks_completed', 'target' => 20, 'weight' => 100],
            ]],
            ['code' => 'STD-DIRECTOR', 'name' => 'KPI Ban giám đốc chuẩn', 'description' => 'Kỷ luật xử lý công việc của ban điều hành.', 'audience' => 'position', 'matches' => ['giam doc', 'director'], 'items' => [
                ['metric' => 'tasks_completed', 'target' => 20, 'weight' => 100],
            ]],
        ];
    }

    private function findOrCreateAudience(int $tenantId, string $type, array $matches, string $bundleCode)
    {
        $class = $type === 'position' ? Position::class : Department::class;
        $found = $class::where('tenant_id', $tenantId)->get()->first(function ($row) use ($matches) {
            $name = Str::lower(Str::ascii($row->name));
            return collect($matches)->contains(fn ($match) => str_contains($name, $match));
        });
        if ($found) return $found;

        $names = [
            'STD-SALES' => 'Kinh doanh', 'STD-WAREHOUSE' => 'Kho', 'STD-PROCUREMENT' => 'Mua hàng',
            'STD-FINANCE' => 'Tài chính', 'STD-MARKETING' => 'Marketing', 'STD-SERVICE' => 'Chăm sóc khách hàng',
            'STD-MANAGER' => 'Trưởng phòng', 'STD-DIRECTOR' => 'Giám đốc',
        ];
        $prefix = $type === 'position' ? 'POS-' : 'DEP-';

        return $class::create(['tenant_id' => $tenantId, 'code' => $prefix.str_replace('STD-', '', $bundleCode), 'name' => $names[$bundleCode]]);
    }

    private function employeeCount(int $tenantId, string $type, int $id): int
    {
        return User::where('tenant_id', $tenantId)->where('is_active', true)
            ->where($type === 'position' ? 'position_id' : 'department_id', $id)->count();
    }
}
