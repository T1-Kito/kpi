<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SalesPipelineStage;
use App\Models\Sku;
use App\Models\User;
use App\Support\DealService;
use App\Support\LeadService;
use App\Support\QuotationService;
use App\Support\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmInheritanceTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CompletesSalesQuotation;

    public function test_conversion_preserves_contact_source_and_is_repeat_safe(): void
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $service = app(LeadService::class);
        $lead = $service->create($actor, ['name' => 'Người liên hệ mới', 'phone' => '0912345678', 'email' => 'new@example.test', 'source' => 'website', 'assigned_to' => $actor->id]);
        $result = $service->qualify($lead, $actor, ['customer_type' => 'organization']);
        $again = $service->qualify($lead, $actor, []);
        $this->assertSame($result['deal']->id, $again['deal']->id);
        $this->assertSame('website', $result['deal']->source);
        $this->assertSame(1, $result['customer']->contacts()->count());
        $this->assertTrue($result['customer']->contacts()->first()->is_primary);
        $this->assertDatabaseCount('deals', 1);
        $this->assertSame(1, \App\Models\Task::where('source_type', 'Deal')->where('source_id', $result['deal']->id)->count());
    }

    public function test_conversion_reuses_customer_with_formatted_vietnamese_phone(): void
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $customer = Customer::create(['tenant_id' => $actor->tenant_id, 'code' => 'CUS-NORMALIZED', 'name' => 'Existing', 'phone' => '0912 345 678', 'status' => 'active', 'customer_type' => 'organization', 'sales_owner_id' => $actor->id]);
        $service = app(LeadService::class);
        $lead = $service->create($actor, ['name' => 'New contact', 'phone' => '+84 912-345-678']);
        $this->assertSame($customer->id, $service->qualify($lead, $actor, [])['customer']->id);
    }

    public function test_pipeline_uses_master_probability_and_rejects_inactive_stage(): void
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        SalesPipelineStage::updateOrCreate(['tenant_id' => $actor->tenant_id, 'code' => 'survey'], ['name' => 'Khảo sát', 'probability' => 37, 'sort_order' => 2, 'type' => 'open', 'is_active' => true]);
        $deal = app(DealService::class)->create($actor, ['name' => 'Master flow', 'stage' => 'survey']);
        $this->assertSame('37.00', $deal->probability);
        SalesPipelineStage::where('tenant_id', $actor->tenant_id)->where('code', 'survey')->update(['is_active' => false]);
        $this->assertArrayNotHasKey('survey', DealService::stages($actor->tenant_id));
    }

    public function test_quotation_api_inherits_deal_customer_items_and_owner(): void
    {
        $this->seed();
        $owner = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $customer = Customer::where('tenant_id', $owner->tenant_id)->where('status', 'active')->firstOrFail();
        $sku = Sku::where('tenant_id', $owner->tenant_id)->where('status', 'active')->firstOrFail();
        $deal = app(DealService::class)->create($owner, ['name' => 'Inherited', 'customer_id' => $customer->id]);
        app(DealService::class)->syncItems($deal, $owner, [['sku_id' => $sku->id, 'quantity' => 3, 'unit_price' => 3500000]]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@vk-kpi.local', 'password' => 'Admin@123'])->json('data.access_token');
        $response = $this->withToken($token)->postJson('/api/v1/quotations', ['deal_id' => $deal->id])->assertCreated()
            ->assertJsonPath('data.customer_id', $customer->id)->assertJsonPath('data.sales_owner_id', $owner->id)->assertJsonCount(1, 'data.items');
        $quotation = \App\Models\Quotation::findOrFail($response->json('data.id'));
        $this->completeSalesQuotation($quotation->id);
        $quotation->refresh();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $order = app(SalesOrderService::class)->createFromQuotation($quotation, $admin);
        $this->assertSame($owner->id, $order->sales_owner_id);
        $this->assertSame($quotation->total_amount, $order->total_amount);
    }

    public function test_quotation_rejects_conflicting_customer_and_inactive_product(): void
    {
        $this->seed();
        $owner = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $customers = Customer::where('tenant_id', $owner->tenant_id)->where('status', 'active')->take(2)->get();
        $sku = Sku::where('tenant_id', $owner->tenant_id)->where('status', 'active')->firstOrFail();
        $deal = app(DealService::class)->create($owner, ['name' => 'Consistency', 'customer_id' => $customers[0]->id]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@vk-kpi.local', 'password' => 'Admin@123'])->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/quotations', ['deal_id' => $deal->id, 'customer_id' => $customers[1]->id,
            'items' => [['sku_id' => $sku->id, 'quantity' => 1]]])->assertUnprocessable();
        $sku->update(['status' => 'inactive']);
        $this->withToken($token)->postJson('/api/v1/quotations', ['customer_id' => $customers[0]->id,
            'items' => [['sku_id' => $sku->id, 'quantity' => 1]]])->assertUnprocessable();
    }
}
