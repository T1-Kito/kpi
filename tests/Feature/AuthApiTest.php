<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_user_can_login_and_get_profile(): void
    {
        $this->seed();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@vk-kpi.local',
            'password' => 'Admin@123',
        ]);

        $login->assertOk()
            ->assertJsonPath('data.user.email', 'admin@vk-kpi.local')
            ->assertJsonPath('data.user.tenant_id', 1);

        $token = $login->json('data.access_token');

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@vk-kpi.local')
            ->assertJsonPath('data.tenant.code', 'VK-KPI');
    }

    public function test_private_api_rejects_missing_token(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }
}
