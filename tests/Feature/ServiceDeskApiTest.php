<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceDeskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_create_ticket_and_warranty_claim_by_serial(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $customer = Customer::where('code', 'CUS-001')->firstOrFail();

        $ticket = $this->withToken($token)->postJson('/api/v1/service-tickets', [
            'customer_id' => $customer->id,
            'serial_number' => 'SER-SVC-001',
            'product_name' => 'Máy in tại quầy lễ tân',
            'subject' => 'Máy in không in được tem',
            'category' => 'warranty',
            'priority' => 'high',
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson('/api/v1/warranty-claims', [
            'customer_id' => $customer->id,
            'serial_numbers' => ['SER-SVC-001', 'SER-SVC-002'],
            'product_name' => 'Máy in tại quầy lễ tân',
            'customer_name' => $customer->name,
            'warranty_start_at' => now()->toDateString(),
            'warranty_months' => 12,
        ])->assertCreated()->assertJsonPath('meta.created', 2)->assertJsonPath('data.0.warranty_status', 'in_warranty');
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Admin@123'])->json('data.access_token');
    }
}
