<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_create_activate_and_accept_a_contract_milestone(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $order = $this->withToken($token)->getJson('/api/v1/sales-orders?page_size=1')->json('data.0');
        $this->withToken($token)->getJson('/api/v1/contracts?page_size=100')->assertOk()->assertJsonPath('data', []);
        $this->withToken($token)->getJson('/api/v1/print-templates/active?module=contract')
            ->assertOk()->assertJsonPath('data.module', 'contract');

        $contract = $this->withToken($token)->postJson('/api/v1/contracts', [
            'name' => 'Hợp đồng cung cấp thiết bị', 'sales_order_id' => $order['id'], 'effective_date' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertCount(1, $contract['milestones']);
        $this->withToken($token)->getJson("/api/v1/contracts/{$contract['id']}/word")
            ->assertStatus(422)->assertJsonPath('message', 'Chưa có file Word cho mẫu Hợp đồng. Vào Mẫu in để tải mẫu Hợp đồng lên và đặt làm mặc định.');

        $this->withToken($token)->postJson("/api/v1/contracts/{$contract['id']}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->withToken($token)->postJson("/api/v1/contracts/{$contract['id']}/milestones/{$contract['milestones'][0]['id']}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Admin@123'])->json('data.access_token');
    }
}
