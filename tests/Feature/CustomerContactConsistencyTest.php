<?php

namespace Tests\Feature;

use App\Models\{Customer, User};
use App\Support\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerContactConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sku_update_preserves_product_details_when_not_submitted(): void
    {
        $this->seed();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@vk-kpi.local', 'password' => 'Admin@123'])->json('data.access_token');
        $sku = \App\Models\Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();
        $sku->product->update(['description' => 'Keep description', 'technical_specs' => [['name' => 'Resolution', 'value' => '300 dpi']]]);
        $this->withToken($token)->putJson("/api/v1/skus/{$sku->id}", ['name' => 'Updated display name'])->assertOk()
            ->assertJsonPath('data.product.description', 'Keep description')
            ->assertJsonPath('data.product.technical_specs.0.value', '300 dpi');
    }

    public function test_primary_contact_is_shared_between_customer_and_directory(): void
    {
        $this->seed();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@vk-kpi.local', 'password' => 'Admin@123'])->json('data.access_token');
        $customer = $this->withToken($token)->postJson('/api/v1/customers', ['name' => 'Shared organization', 'customer_type' => 'organization', 'contact_name' => 'First', 'phone' => '0912340001'])->assertCreated()->json('data');
        $model = Customer::findOrFail($customer['id']);
        $this->assertSame(1, $model->contacts()->count());
        $second = $this->withToken($token)->postJson("/api/v1/customers/{$model->id}/contacts", ['name' => 'Second', 'phone' => '0912340002', 'email' => 'second@example.test', 'is_primary' => false])->assertCreated()->json('data');
        $this->withToken($token)->putJson("/api/v1/customers/{$model->id}", ['name' => $model->name, 'primary_contact_id' => $second['id'], 'contact_name' => 'Stale text'])->assertOk()->assertJsonPath('data.contact_name', 'Second')->assertJsonPath('data.phone', '0912340002');
        $this->assertSame(1, $model->contacts()->where('is_primary', true)->count());
        $this->withToken($token)->putJson("/api/v1/customers/{$model->id}", ['name' => $model->name, 'contact_name' => 'Updated', 'phone' => '0912340003'])->assertOk();
        $this->assertDatabaseHas('customer_contacts', ['id' => $second['id'], 'name' => 'Updated', 'phone' => '0912340003']);
        $this->withToken($token)->putJson("/api/v1/customers/{$model->id}/contacts/{$second['id']}", ['name' => 'Directory edit', 'phone' => '0912340004', 'is_primary' => true])->assertOk();
        $this->assertSame('Directory edit', $model->fresh()->contact_name);
        $other = Customer::create(['tenant_id' => $model->tenant_id, 'code' => 'OTHER', 'name' => 'Other', 'customer_type' => 'organization']);
        $foreignContact = $other->contacts()->create(['name' => 'Foreign', 'is_primary' => true]);
        $this->withToken($token)->putJson("/api/v1/customers/{$model->id}", ['name' => $model->name, 'primary_contact_id' => $foreignContact->id])->assertUnprocessable();
    }

    public function test_person_conversion_does_not_create_fake_organization_or_contact(): void
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $service = app(LeadService::class);
        $lead = $service->create($actor, ['name' => 'Retail buyer', 'phone' => '0912000987']);
        $result = $service->qualify($lead, $actor, ['customer_type' => 'person']);
        $this->assertSame('person', $result['customer']->customer_type);
        $this->assertSame(0, $result['customer']->contacts()->count());
        $this->assertSame($result['deal']->id, $service->qualify($lead, $actor, [])['deal']->id);
    }

    public function test_conversion_matches_existing_organization_by_secondary_contact(): void
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $customer = Customer::create(['tenant_id' => $actor->tenant_id, 'code' => 'CONTACT-MATCH', 'name' => 'Organization', 'customer_type' => 'organization', 'status' => 'active', 'sales_owner_id' => $actor->id]);
        $customer->contacts()->create(['name' => 'Secondary', 'phone' => '0912 000 456', 'is_primary' => false]);
        $service = app(LeadService::class);
        $lead = $service->create($actor, ['name' => 'Secondary', 'phone' => '+84 912000456']);
        $this->assertSame($customer->id, $service->qualify($lead, $actor, [])['customer']->id);
        $this->assertSame(1, $customer->contacts()->count());
    }
}
