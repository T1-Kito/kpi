<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_and_open_audit_log_detail(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');

        $index = $this->withToken($token)
            ->getJson('/api/v1/audit-logs?action=login_success')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'login_success');

        $logId = $index->json('data.0.id');

        $this->withToken($token)
            ->getJson("/api/v1/audit-logs/{$logId}")
            ->assertOk()
            ->assertJsonPath('data.id', $logId)
            ->assertJsonPath('data.user.email', 'admin@vk-kpi.local');
    }

    public function test_user_without_audit_permission_cannot_view_audit_logs(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');

        $this->withToken($token)
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
