<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_seeded_master_data_and_create_customer(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');

        $this->withToken($token)
            ->getJson('/api/v1/customers?q=CUS')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $create = $this->withToken($token)
            ->postJson('/api/v1/customers', [
                'name' => 'Khách hàng mới',
                'phone' => '0919000000',
                'credit_limit' => 10000000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Khách hàng mới');

        $this->assertMatchesRegularExpression('/^CUS-\d{5}$/', $create->json('data.code'));
    }

    public function test_sales_can_view_but_cannot_manage_master_data(): void
    {
        $this->seed();
        $token = $this->loginAs('sales@vk-kpi.local');

        $this->withToken($token)
            ->getJson('/api/v1/skus?q=SKU')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->withToken($token)
            ->postJson('/api/v1/suppliers', [
                'name' => 'Nhà cung cấp bị chặn',
            ])
            ->assertForbidden();
    }

    public function test_customer_duplicate_check_and_normalized_tax_code_are_tenant_scoped(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $first = $this->withToken($token)->postJson('/api/v1/customers', [
            'name' => 'Công ty kiểm tra trùng',
            'tax_code' => '031 123 4567',
            'phone' => '0901 234 567',
            'email' => 'sales@duplicate.example',
        ])->assertCreated()->json('data');

        $this->withToken($token)->getJson('/api/v1/customers/duplicates?phone=0901234567')
            ->assertOk()->assertJsonPath('data.0.id', $first['id']);
        $this->withToken($token)->getJson('/api/v1/customers/duplicates?email=SALES%40duplicate.example')
            ->assertOk()->assertJsonPath('data.0.id', $first['id']);
        $this->withToken($token)->postJson('/api/v1/customers', [
            'name' => 'Bản trùng mã số thuế',
            'tax_code' => '031.123.4567',
        ])->assertStatus(409);
        $this->assertDatabaseCount('customers', 3);
    }

    public function test_organization_can_have_multiple_contacts_with_one_primary_and_audit_history(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $customer = $this->withToken($token)->postJson('/api/v1/customers', [
            'name' => 'Trường Sơn Tiên', 'customer_type' => 'organization',
        ])->assertCreated()->json('data');
        $first = $this->withToken($token)->postJson("/api/v1/customers/{$customer['id']}/contacts", [
            'name' => 'Chị Lan', 'position' => 'Điều phối', 'email' => 'lan@example.com',
        ])->assertCreated()->assertJsonPath('data.is_primary', true)->json('data');
        $second = $this->withToken($token)->postJson("/api/v1/customers/{$customer['id']}/contacts", [
            'name' => 'Anh Minh', 'position' => 'Kế toán', 'is_primary' => true,
        ])->assertCreated()->json('data');
        $this->withToken($token)->getJson("/api/v1/customers/{$customer['id']}")
            ->assertOk()->assertJsonCount(2, 'data.contacts')->assertJsonPath('data.contacts.0.id', $second['id']);
        $this->assertDatabaseHas('customer_contacts', ['id' => $first['id'], 'is_primary' => false]);
        $this->withToken($token)->deleteJson("/api/v1/customers/{$customer['id']}/contacts/{$second['id']}")->assertOk();
        $this->assertDatabaseHas('customer_contacts', ['id' => $first['id'], 'is_primary' => true]);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'customer_contact', 'action' => 'delete_customer_contact']);
    }

    public function test_contact_cannot_be_added_to_person_or_edited_through_another_customer(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $person = $this->withToken($token)->postJson('/api/v1/customers', [
            'name' => 'Khách cá nhân', 'customer_type' => 'person',
        ])->assertCreated()->json('data');
        $this->withToken($token)->postJson("/api/v1/customers/{$person['id']}/contacts", ['name' => 'Sai'])
            ->assertStatus(422);

        $organization = $this->withToken($token)->postJson('/api/v1/customers', [
            'name' => 'Tổ chức khác', 'customer_type' => 'organization',
        ])->assertCreated()->json('data');
        $contact = $this->withToken($token)->postJson("/api/v1/customers/{$organization['id']}/contacts", [
            'name' => 'Người liên hệ',
        ])->assertCreated()->json('data');
        $this->withToken($token)->putJson("/api/v1/customers/{$person['id']}/contacts/{$contact['id']}", [
            'name' => 'Không được sửa',
        ])->assertNotFound();
    }

    public function test_admin_can_update_master_data(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $customer = Customer::where('code', 'CUS-001')->firstOrFail();
        $supplier = Supplier::where('code', 'SUP-001')->firstOrFail();
        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();
        $warehouse = Warehouse::where('code', 'WH-HCM')->firstOrFail();

        $this->withToken($token)
            ->putJson("/api/v1/customers/{$customer->id}", [
                'name' => 'Khách hàng Minh An cập nhật',
                'contact_name' => 'Anh Minh',
                'phone' => '0901000001',
                'email' => 'minhan@example.com',
                'credit_limit' => 250000000,
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Khách hàng Minh An cập nhật');

        $this->withToken($token)
            ->putJson("/api/v1/suppliers/{$supplier->id}", [
                'name' => 'Nhà cung cấp Ánh Dương cập nhật',
                'phone' => '0902000001',
                'email' => 'sales@anhduong.example.com',
                'terms' => 'Thanh toán 15 ngày',
                'rating' => 5,
                'supplied_products' => 'Thiết bị văn phòng',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.rating', 5);

        $this->withToken($token)
            ->putJson("/api/v1/skus/{$sku->id}", [
                'product_name' => 'Máy in mã vạch',
                'name' => 'Máy in mã vạch VK-100 cập nhật',
                'barcode' => '893000000001',
                'unit' => 'pcs',
                'min_stock' => 6,
                'max_stock' => 60,
                'sale_price' => 3600000,
                'cost_price' => 2600000,
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.min_stock', 6);

        $this->withToken($token)
            ->putJson("/api/v1/warehouses/{$warehouse->id}", [
                'name' => 'Kho TP.HCM cập nhật',
                'address' => 'Quận 7, TP.HCM',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Kho TP.HCM cập nhật');

        $this->assertDatabaseHas('audit_logs', ['action' => 'update_customer', 'entity_id' => $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_supplier', 'entity_id' => $supplier->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_sku', 'entity_id' => $sku->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_warehouse', 'entity_id' => $warehouse->id]);
    }

    public function test_person_identity_is_separate_and_validated(): void
    {
        $this->seed();
        $token = $this->loginAs('admin@vk-kpi.local');
        $payload = ['name' => 'Khách lẻ', 'customer_type' => 'person', 'identity_number' => '001234567890'];
        $customer = $this->withToken($token)->postJson('/api/v1/customers', $payload)
            ->assertCreated()->assertJsonPath('data.identity_number', '001234567890')->assertJsonPath('data.tax_code', null)->json('data');
        $this->withToken($token)->putJson('/api/v1/customers/'.$customer['id'], $payload)->assertOk();
        $this->withToken($token)->postJson('/api/v1/customers', $payload)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/customers', [...$payload, 'identity_number' => '123abc'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/customers', ['name' => 'Khách chưa cung cấp CCCD', 'customer_type' => 'person'])->assertCreated();
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
