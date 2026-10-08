<?php

namespace Tests\Feature;

use App\Models\KpiDefinition;
use App\Models\KpiAdjustment;
use App\Models\KpiException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_logged_in_user_can_view_kpi_overview(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');

        $this->withToken($token)
            ->getJson('/api/v1/kpi/overview')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'metrics' => ['totalScore', 'salesScore', 'inventoryScore', 'procurementScore', 'workflowScore'],
                    'targets',
                    'exceptions',
                ],
            ]);
    }

    public function test_admin_can_calculate_kpi_snapshot(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');

        $this->withToken($token)
            ->postJson('/api/v1/kpi/snapshots/calculate', ['period_type' => 'day'])
            ->assertCreated()
            ->assertJsonPath('data.period_type', 'day');

        $this->assertDatabaseCount('kpi_score_snapshots', 1);
        $this->assertDatabaseHas('kpi_targets', ['period_type' => 'day']);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'kpi_score_snapshot',
            'action' => 'calculate_kpi_snapshot',
        ]);
    }

    public function test_recalculation_preserves_locked_kpi_target(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $this->withToken($token)
            ->postJson('/api/v1/kpi/snapshots/calculate', ['period_type' => 'day'])
            ->assertCreated();

        $target = \App\Models\KpiTarget::query()->firstOrFail();
        $this->withToken($token)
            ->postJson("/api/v1/kpi/targets/{$target->id}/lock")
            ->assertOk();
        $lockedAt = $target->fresh()->locked_at;
        $score = $target->fresh()->score;

        $this->withToken($token)
            ->postJson('/api/v1/kpi/snapshots/calculate', ['period_type' => 'day'])
            ->assertCreated();

        $this->assertSame('locked', $target->fresh()->status);
        $this->assertEquals($lockedAt, $target->fresh()->locked_at);
        $this->assertEquals($score, $target->fresh()->score);
    }

    public function test_admin_can_create_and_review_kpi_exception(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $definition = KpiDefinition::where('code', 'KPI-WORKFLOW')->firstOrFail();

        $create = $this->withToken($token)
            ->postJson('/api/v1/kpi/exceptions', [
                'kpi_definition_id' => $definition->id,
                'period_type' => 'month',
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'reason' => 'Có chiến dịch kiểm thử làm phát sinh nhiều công việc demo.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $exceptionId = $create->json('data.id');

        $this->withToken($token)
            ->postJson("/api/v1/kpi/exceptions/{$exceptionId}/review", [
                'status' => 'approved',
                'review_note' => 'Đã ghi nhận ngoại lệ demo.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('approved', KpiException::findOrFail($exceptionId)->status);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'kpi_exception',
            'entity_id' => $exceptionId,
            'action' => 'create_kpi_exception',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'kpi_exception',
            'entity_id' => $exceptionId,
            'action' => 'review_kpi_exception',
        ]);
    }

    public function test_admin_can_create_review_and_summarize_kpi_adjustment(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $definition = KpiDefinition::where('code', 'KPI-WORKFLOW')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();

        $create = $this->withToken($token)
            ->postJson('/api/v1/kpi/adjustments', [
                'user_id' => $sales->id,
                'kpi_definition_id' => $definition->id,
                'adjustment_type' => 'penalty',
                'points' => 5,
                'period_type' => 'month',
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'reason' => 'Trễ SLA xử lý báo giá cần ghi nhận vào sổ điểm.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $adjustmentId = $create->json('data.id');

        $this->withToken($token)
            ->postJson("/api/v1/kpi/adjustments/{$adjustmentId}/review", [
                'status' => 'approved',
                'review_note' => 'Đã kiểm tra dữ liệu SLA.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $summary = $this->withToken($token)
            ->getJson('/api/v1/kpi/adjustments/summary?period_type=month')
            ->assertOk()
            ->json('data');

        $salesSummary = collect($summary)->firstWhere('user.email', 'sales@vk-kpi.local');
        $this->assertNotNull($salesSummary);
        $this->assertEquals(3, $salesSummary['net_points']);

        $this->assertSame('approved', KpiAdjustment::findOrFail($adjustmentId)->status);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'kpi_adjustment',
            'entity_id' => $adjustmentId,
            'action' => 'create_kpi_adjustment',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'kpi_adjustment',
            'entity_id' => $adjustmentId,
            'action' => 'review_kpi_adjustment',
        ]);
    }

    public function test_kpi_definition_code_is_generated_by_system(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');

        $response = $this->withToken($token)
            ->postJson('/api/v1/kpi/definitions', [
                'code' => 'KPI-HACK-001',
                'name' => 'Chi tieu auto code',
                'source_type' => 'manual',
                'formula' => 'manual',
                'unit' => 'diem',
                'target_direction' => 'increase',
                'weight' => 5,
                'status' => 'active',
            ])
            ->assertCreated();

        $code = $response->json('data.code');
        $this->assertNotSame('KPI-HACK-001', $code);
        $this->assertMatchesRegularExpression('/^KPI-\d{5}$/', $code);
        $this->assertDatabaseMissing('kpi_definitions', ['code' => 'KPI-HACK-001']);
    }
    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
