<?php

namespace Tests\Feature;

use App\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealPipelineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_create_and_transition_a_deal_with_version_check(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $customerId = $this->withToken($token)->getJson('/api/v1/customers?page_size=1')->json('data.0.id');

        $deal = $this->withToken($token)->postJson('/api/v1/deals', [
            'customer_id' => $customerId,
            'name' => 'Cơ hội triển khai phần mềm kho',
            'amount' => 25000000,
            'expected_close_date' => now()->addMonth()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.stage', 'new')->json('data');

        $sku = $this->withToken($token)->getJson('/api/v1/skus?page_size=1')->json('data.0');
        $this->withToken($token)->putJson("/api/v1/deals/{$deal['id']}/items", [
            'items' => [['sku_id' => $sku['id'], 'quantity' => 2, 'unit_price' => 125000]],
        ])->assertOk()->assertJsonPath('data.amount', '250000.00');
        $this->withToken($token)->postJson("/api/v1/deals/{$deal['id']}/activities", [
            'title' => 'Gọi xác nhận nhu cầu', 'priority' => 'high', 'due_at' => now()->addDay()->toDateTimeString(),
        ])->assertCreated()->assertJsonPath('data.title', 'Gọi xác nhận nhu cầu');
        $this->withToken($token)->getJson("/api/v1/deals/{$deal['id']}")
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonCount(1, 'data.tasks');

        $this->withToken($token)->postJson("/api/v1/deals/{$deal['id']}/transition", [
            'stage' => 'proposal', 'version' => $deal['version'],
        ])->assertOk()->assertJsonPath('data.stage', 'proposal')->assertJsonPath('data.probability', '50.00');

        $this->withToken($token)->postJson("/api/v1/deals/{$deal['id']}/transition", [
            'stage' => 'negotiation', 'version' => $deal['version'],
        ])->assertStatus(409);

        $this->assertDatabaseHas('deal_stage_histories', ['deal_id' => $deal['id'], 'to_stage' => 'proposal']);
        $this->assertSame('proposal', Deal::findOrFail($deal['id'])->stage);
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
