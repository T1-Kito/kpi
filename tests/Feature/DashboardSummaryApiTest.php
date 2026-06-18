<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_load_dashboard_summary_in_one_request(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');

        $this->withToken($token)
            ->getJson('/api/v1/dashboard/summary?type=admin')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'tasks' => ['data', 'meta'],
                    'alerts' => ['data', 'meta'],
                    'leads' => ['data', 'meta'],
                    'quotations' => ['data', 'meta'],
                    'pendingQuotes' => ['data', 'meta'],
                    'salesOrders' => ['data', 'meta'],
                    'balances' => ['data', 'meta'],
                    'purchaseRequests' => ['data', 'meta'],
                    'purchaseOrders' => ['data', 'meta'],
                ],
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
