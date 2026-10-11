<?php

namespace Tests\Feature;

use App\Models\KpiAdjustment;
use App\Models\KpiAssignment;
use App\Models\KpiDefinition;
use App\Models\KpiPeriodClosure;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiPeriodClosureTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Admin@123'])->assertOk()->json('data.access_token');
    }

    private function assignedBundle(User $admin, User $sales, string $token): KpiAssignment
    {
        $definitions = KpiDefinition::where('tenant_id', $admin->tenant_id)->where('status', 'active')->take(2)->get();
        $bundleId = $this->withToken($token)->postJson('/api/v1/kpi/bundles', ['code' => 'CLOSE', 'name' => 'Bộ chốt kỳ', 'items' => [
            ['kpi_definition_id' => $definitions[0]->id, 'metric' => 'tasks_completed', 'target_value' => 10, 'weight' => 60, 'direction' => 'increase'],
            ['kpi_definition_id' => $definitions[1]->id, 'metric' => 'leads_assigned', 'target_value' => 5, 'weight' => 40, 'direction' => 'increase'],
        ]])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("/api/v1/kpi/bundles/{$bundleId}/publish")->assertOk();
        $this->withToken($token)->postJson("/api/v1/kpi/bundles/{$bundleId}/apply", [
            'audience_type' => 'user', 'audience_id' => $sales->id, 'period_type' => 'month', 'date' => '2026-09-12', 'status' => 'active',
        ])->assertCreated();
        return KpiAssignment::where('kpi_bundle_id', $bundleId)->firstOrFail();
    }

    public function test_period_close_is_atomic_blocks_pending_adjustments_and_keeps_reopen_history(): void
    {
        $this->seed();
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00'));
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $token = $this->token($admin);
        $anchor = $this->assignedBundle($admin, $sales, $token);
        $adjustment = KpiAdjustment::create([
            'tenant_id' => $admin->tenant_id, 'user_id' => $sales->id, 'created_by' => $admin->id,
            'adjustment_type' => 'bonus', 'points' => 2, 'period_type' => 'month', 'period_start' => '2026-09-01',
            'period_end' => '2026-09-30', 'reason' => 'Bổ sung bằng chứng', 'status' => 'pending',
        ]);

        $this->withToken($token)->postJson('/api/v1/kpi/period-closures/close', ['assignment_id' => $anchor->id])
            ->assertUnprocessable()->assertJsonStructure(['fields' => ['period']]);
        $this->assertSame(0, KpiAssignment::whereNotNull('locked_at')->count(), 'Không được khóa nửa bộ KPI.');

        $adjustment->update(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        $closureId = $this->withToken($token)->postJson('/api/v1/kpi/period-closures/close', ['assignment_id' => $anchor->id])
            ->assertCreated()->assertJsonPath('data.revision', 1)->assertJsonPath('data.status', 'employee_review')->json('data.id');
        $this->assertSame(2, KpiAssignment::where('status', 'locked')->whereNotNull('locked_results')->count());
        $closure = KpiPeriodClosure::findOrFail($closureId);
        $this->assertCount(2, $closure->snapshot['assignments']);
        $this->assertSame([$sales->id], $closure->member_ids);
        $this->withToken($token)->postJson('/api/v1/kpi/period-closures/close', ['assignment_id' => $anchor->id])->assertUnprocessable();

        $salesToken = $this->token($sales);
        $ackId = $this->withToken($salesToken)->getJson('/api/v1/kpi/period-closures/mine')->assertOk()
            ->assertJsonPath('data.0.status', 'pending')->json('data.0.id');
        $this->withToken($salesToken)->postJson("/api/v1/kpi/period-closures/{$closureId}/respond", ['action' => 'dispute', 'note' => 'ngắn'])->assertUnprocessable();
        $this->withToken($salesToken)->postJson("/api/v1/kpi/period-closures/{$closureId}/respond", ['action' => 'dispute', 'note' => 'Thiếu một công việc đã hoàn thành trong kỳ.'])
            ->assertOk()->assertJsonPath('data.status', 'disputed');
        $this->withToken($token)->postJson("/api/v1/kpi/period-closures/{$closureId}/finalize")->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/kpi/period-acknowledgements/{$ackId}/resolve", ['note' => 'Đã kiểm tra chứng từ và cập nhật số liệu liên quan.'])
            ->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->withToken($token)->postJson("/api/v1/kpi/period-closures/{$closureId}/finalize")
            ->assertOk()->assertJsonPath('data.status', 'closed');

        $this->withToken($token)->postJson("/api/v1/kpi/period-closures/{$closureId}/reopen", ['reason' => 'ngắn'])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/kpi/period-closures/{$closureId}/reopen", ['reason' => 'Mở lại để bổ sung chứng từ đã xác minh.'])
            ->assertOk()->assertJsonPath('data.status', 'reopened');
        $this->assertSame(2, KpiAssignment::where('status', 'active')->whereNull('locked_at')->count());
        $this->assertCount(2, KpiPeriodClosure::findOrFail($closureId)->snapshot['assignments'], 'Ảnh chụp lịch sử phải còn nguyên sau khi mở lại.');

        $this->withToken($token)->postJson('/api/v1/kpi/period-closures/close', ['assignment_id' => $anchor->id])
            ->assertCreated()->assertJsonPath('data.revision', 2)->assertJsonPath('data.status', 'employee_review');
    }

    public function test_sales_user_cannot_close_or_reopen_period(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $anchor = $this->assignedBundle($admin, $sales, $this->token($admin));
        $this->withToken($this->token($sales))->postJson('/api/v1/kpi/period-closures/close', ['assignment_id' => $anchor->id])->assertForbidden();
    }
}
