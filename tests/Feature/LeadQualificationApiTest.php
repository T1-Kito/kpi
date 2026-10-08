<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadQualificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_qualify_a_lead_into_customer_and_deal(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $userId = $this->withToken($token)->getJson('/api/v1/me')->json('data.id');

        $lead = $this->withToken($token)->postJson('/api/v1/leads', [
            'name' => 'Nguyễn Văn Cơ Hội',
            'phone' => '0909123456',
            'email' => 'cohoi@example.test',
            'assigned_to' => $userId,
        ])->assertCreated()->json('data');

        $result = $this->withToken($token)->postJson("/api/v1/leads/{$lead['id']}/qualify", [
            'deal_name' => 'Triển khai máy in cho Nguyễn Văn Cơ Hội',
            'amount' => 12500000,
            'expected_close_date' => now()->addDays(14)->toDateString(),
        ])->assertOk()->assertJsonPath('data.lead.status', 'qualified')->assertJsonPath('data.deal.stage', 'qualified')->json('data');

        $this->assertDatabaseHas('customers', ['id' => $result['customer']['id'], 'phone' => '0909123456']);
        $this->assertDatabaseHas('deals', ['id' => $result['deal']['id'], 'lead_id' => $lead['id'], 'customer_id' => $result['customer']['id']]);
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
