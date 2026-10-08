<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesMasterDataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_sales_pipeline_and_opportunity_sources(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $this->withToken($token)->getJson('/api/v1/sales-master-data')->assertOk()->assertJsonCount(6, 'data.pipeline_stages');

        $this->withToken($token)->postJson('/api/v1/sales-master-data/sources', [
            'code' => 'event', 'name' => 'Sự kiện khách hàng', 'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.code', 'event');

        $stage = $this->withToken($token)->postJson('/api/v1/sales-master-data/stages', [
            'code' => 'demo', 'name' => 'Demo giải pháp', 'probability' => 40, 'sort_order' => 25, 'type' => 'open',
        ])->assertCreated()->json('data');
        $this->assertDatabaseHas('sales_pipeline_stages', ['id' => $stage['id'], 'name' => 'Demo giải pháp']);
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Admin@123'])->json('data.access_token');
    }
}
