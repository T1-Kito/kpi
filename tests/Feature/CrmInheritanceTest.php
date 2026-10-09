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

    public function test_conversion_sets_next_activity_from_generated_follow_up_without_manual_date(): void
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $service = app(LeadService::class);
        $lead = $service->create($actor, ['name' => 'Automatic follow up', 'phone' => '0918877665', 'assigned_to' => $actor->id]);
        $result = $service->qualify($lead, $actor, []);
        $task = \App\Models\Task::where('source_type', 'Deal')->where('source_id', $result['deal']->id)->firstOrFail();
        $this->assertNotNull($result['deal']->next_activity_at);
        $this->assertTrue($task->due_at->equalTo($result['deal']->next_activity_at));
        $this->assertSame($actor->id, $task->assignee_id);
        $duration = \App\Models\SlaPolicy::where('tenant_id', $actor->tenant_id)->where('task_type', 'lead_follow_up')->where('priority', 'normal')->value('duration_minutes');
        $this->assertEqualsWithDelta($duration, now()->diffInMinutes($task->due_at), 1);
        $again = $service->qualify($lead, $actor, []);
        $this->assertSame($result['deal']->id, $again['deal']->id);
        $this->assertSame(1, \App\Models\Task::where('source_type', 'Deal')->where('source_id', $result['deal']->id)->count());
    }

    public function test_conversion_keeps_explicit_follow_up_date_on_task_and_deal(): void
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $service = app(LeadService::class);
        $lead = $service->create($actor, ['name' => 'Explicit follow up', 'phone' => '0918877666']);
        $due = now()->addDays(3)->startOfMinute();
        $result = $service->qualify($lead, $actor, ['next_activity_at' => $due->toDateTimeString()]);
        $task = \App\Models\Task::where('source_type', 'Deal')->where('source_id', $result['deal']->id)->firstOrFail();
        $this->assertTrue($due->equalTo($result['deal']->next_activity_at));
        $this->assertTrue($due->equalTo($task->due_at));
    }

    public function test_dedicated_opportunity_sla_takes_priority_over_lead_sla(): void
    {
        $this->seed();
        $actor = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        \App\Models\SlaPolicy::create(['tenant_id' => $actor->tenant_id, 'module' => 'sales', 'task_type' => 'deal_follow_up', 'priority' => 'normal', 'duration_minutes' => 90, 'warning_before_minutes' => 15]);
        $service = app(LeadService::class);
        $lead = $service->create($actor, ['name' => 'Custom SLA', 'phone' => '0918877667']);
        $result = $service->qualify($lead, $actor, []);
        $this->assertEqualsWithDelta(90, now()->diffInMinutes($result['deal']->next_activity_at), 1);
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
