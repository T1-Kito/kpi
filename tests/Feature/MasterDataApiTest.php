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

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Admin@123',
        ])->json('data.access_token');
    }
}
