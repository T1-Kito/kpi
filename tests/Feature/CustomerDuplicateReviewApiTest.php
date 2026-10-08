<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerDuplicateReviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_directory_lists_searches_and_updates_customer_contacts(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        [$customer] = $this->pair('0901000001', '0901000002');
        $customer->update(['customer_type' => 'organization']);
        $contact = $this->withToken($token)->postJson("/api/v1/customers/{$customer->id}/contacts", ['name' => 'Directory Contact', 'email' => 'directory@example.com', 'position' => 'Kế toán'])->assertCreated()->json('data.id');
        $this->withToken($token)->getJson('/api/v1/customer-contacts?q=Directory')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.customer.id', $customer->id)->assertJsonPath('data.0.is_primary', true);
        $this->withToken($token)->putJson("/api/v1/customers/{$customer->id}/contacts/{$contact}", ['name' => 'Updated Directory', 'phone' => '0901111222'])->assertOk();
        $this->withToken($token)->getJson('/api/v1/customer-contacts?q=0901111222')->assertOk()->assertJsonPath('data.0.name', 'Updated Directory');
        $this->withToken($token)->getJson('/api/v1/customer-contacts?q=not-present')->assertOk()->assertJsonPath('meta.total', 0);
        $new = $this->withToken($token)->postJson('/api/v1/customer-contacts', ['customer_name' => 'New Inline Customer', 'name' => 'Inline Contact'])->assertCreated()->json('data');
        $this->assertSame('New Inline Customer', $new['customer']['name']);
        $this->assertNotEmpty($new['customer']['code']);
        $this->withToken($token)->postJson('/api/v1/customer-contacts', ['customer_name' => 'New Inline Customer', 'name' => 'Duplicate'])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/customer-contacts', ['customer_id' => $new['customer_id'], 'name' => 'Second Contact'])->assertCreated();
    }

    public function test_admin_can_scan_and_dismiss_without_reopening_review(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        [$first, $second] = $this->pair('090 888 7777', '0908887777');

        $this->withToken($token)->postJson('/api/v1/customer-duplicate-reviews/scan')->assertOk()
            ->assertJsonPath('data.new_reviews', 1);
        $review = $this->withToken($token)->getJson('/api/v1/customer-duplicate-reviews')->assertOk()
            ->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame($first->id, $review['first_customer_id']);
        $this->assertSame($second->id, $review['second_customer_id']);
        $this->withToken($token)->postJson("/api/v1/customer-duplicate-reviews/{$review['id']}/dismiss", [
            'reason' => 'Hai công ty dùng chung số tổng đài.',
        ])->assertOk();
        $this->withToken($token)->postJson('/api/v1/customer-duplicate-reviews/scan')->assertOk()
            ->assertJsonPath('data.new_reviews', 0);
        $this->withToken($token)->getJson('/api/v1/customer-duplicate-reviews')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseHas('customer_duplicate_reviews', ['id' => $review['id'], 'status' => 'not_duplicate']);
    }

    public function test_merge_relinks_history_keeps_source_and_records_audit(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        [$retained, $source] = $this->pair('091 222 3333', '0912223333');
        $contact = CustomerContact::create(['customer_id' => $source->id, 'name' => 'Chị Hà', 'is_primary' => true]);
        $lead = Lead::create([
            'tenant_id' => $source->tenant_id, 'code' => 'LEAD-MERGE-TEST', 'name' => 'Nguồn cũ',
            'phone' => '0912223333', 'customer_id' => $source->id, 'status' => 'new',
        ]);
        $this->withToken($token)->postJson('/api/v1/customer-duplicate-reviews/scan')->assertOk();
        $reviewId = $this->withToken($token)->getJson('/api/v1/customer-duplicate-reviews')->json('data.0.id');
        $this->withToken($token)->postJson("/api/v1/customer-duplicate-reviews/{$reviewId}/merge", [
            'retained_customer_id' => $retained->id,
            'reason' => 'Đã đối chiếu đầu mối và xác nhận cùng tổ chức.',
        ])->assertOk()->assertJsonPath('data.id', $retained->id);

        $this->assertDatabaseHas('customers', ['id' => $source->id, 'merged_into_id' => $retained->id, 'status' => 'inactive']);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'customer_id' => $retained->id]);
        $this->assertDatabaseHas('customer_contacts', ['id' => $contact->id, 'customer_id' => $retained->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'merge_customer', 'tenant_id' => $retained->tenant_id]);
        $this->withToken($token)->postJson("/api/v1/customer-duplicate-reviews/{$reviewId}/merge", [
            'retained_customer_id' => $retained->id,
            'reason' => 'Không được gộp lại lần nữa.',
        ])->assertStatus(409);
    }

    public function test_sales_user_cannot_review_or_merge(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');
        $this->withToken($token)->getJson('/api/v1/customer-duplicate-reviews')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/customer-duplicate-reviews/scan')->assertForbidden();
    }

    private function pair(string $firstPhone, string $secondPhone): array
    {
        $tenantId = Customer::query()->value('tenant_id');
        $first = Customer::create(['tenant_id' => $tenantId, 'code' => 'CUS-DUP-01', 'name' => 'Công ty A', 'phone' => $firstPhone]);
        $second = Customer::create(['tenant_id' => $tenantId, 'code' => 'CUS-DUP-02', 'name' => 'Công ty A chi nhánh', 'phone' => $secondPhone]);
        return [$first, $second];
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Admin@123'])->json('data.access_token');
    }
}
