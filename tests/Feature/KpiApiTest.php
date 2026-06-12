<?php

namespace Tests\Feature;

use App\Models\KpiDefinition;
use App\Models\KpiException;
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

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
