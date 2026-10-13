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

    public function test_inactive_defaults_are_rejected_and_replacing_default_preserves_existing_records(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        foreach (['payment-terms' => ['due_days' => 30], 'price-books' => ['discount_percent' => 5]] as $path => $fields) {
            $table = $path === 'payment-terms' ? 'sales_payment_terms' : 'sales_price_books';
            $first = $this->withToken($token)->postJson('/api/v1/sales-master-data/'.$path, $fields + [
                'code' => 'first-default', 'name' => 'Mặc định đầu', 'is_active' => true, 'is_default' => true,
            ])->assertCreated()->json('data');
            $this->withToken($token)->postJson('/api/v1/sales-master-data/'.$path, $fields + [
                'code' => 'invalid-default', 'name' => 'Ngừng dùng', 'is_active' => false, 'is_default' => true,
            ])->assertUnprocessable()->assertJsonStructure(['fields' => ['is_default']]);
            $this->assertDatabaseHas($table, ['id' => $first['id'], 'is_default' => true]);
            $this->withToken($token)->putJson('/api/v1/sales-master-data/'.$path.'/'.$first['id'], $fields + [
                'name' => 'Ngừng dùng', 'is_active' => false, 'is_default' => true,
            ])->assertUnprocessable();
            $second = $this->withToken($token)->postJson('/api/v1/sales-master-data/'.$path, $fields + [
                'code' => 'second-default', 'name' => 'Mặc định mới', 'is_active' => true, 'is_default' => true,
            ])->assertCreated()->json('data');
            $this->assertDatabaseHas($table, ['id' => $first['id'], 'is_default' => false, 'is_active' => true]);
            $this->assertDatabaseHas($table, ['id' => $second['id'], 'is_default' => true]);
        }
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Admin@123'])->json('data.access_token');
    }
}
