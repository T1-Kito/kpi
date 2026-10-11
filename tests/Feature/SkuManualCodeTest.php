<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkuManualCodeTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): void
    {
        $this->seed();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@vk-kpi.local', 'password' => 'Admin@123'])->json('data.access_token');
        $this->withToken($token);
    }

    public function test_create_accepts_manual_product_and_sku_codes(): void
    {
        $this->authenticate();
        $this->postJson('/api/v1/skus', ['name' => 'Máy in', 'product_code' => ' SP-VK-01 ', 'sku_code' => ' VK-100 '])
            ->assertCreated()->assertJsonPath('data.sku_code', 'VK-100')->assertJsonPath('data.product.code', 'SP-VK-01');
    }

    public function test_blank_codes_are_generated_and_do_not_overwrite_existing_on_edit(): void
    {
        $this->authenticate();
        $response = $this->postJson('/api/v1/skus', ['name' => 'Máy in', 'product_code' => '', 'sku_code' => ''])->assertCreated();
        $sku = $response->json('data');
        $this->assertMatchesRegularExpression('/^SKU-\d{5}$/', $sku['sku_code']);
        $this->assertMatchesRegularExpression('/^PROD-\d{5}$/', $sku['product']['code']);
        $this->putJson('/api/v1/skus/'.$sku['id'], ['name' => 'Máy in mới', 'product_code' => '', 'sku_code' => ''])
            ->assertOk()->assertJsonPath('data.sku_code', $sku['sku_code'])->assertJsonPath('data.product.code', $sku['product']['code']);
    }

    public function test_duplicate_codes_are_rejected_without_creating_or_updating_records(): void
    {
        $this->authenticate();
        $existing = Sku::with('product')->firstOrFail();
        $productCount = Product::count();
        $skuCount = Sku::count();
        $this->postJson('/api/v1/skus', ['name' => 'Duplicate', 'sku_code' => $existing->sku_code])
            ->assertUnprocessable()->assertJsonStructure(['fields' => ['sku_code']]);
        $this->postJson('/api/v1/skus', ['name' => 'Duplicate', 'product_code' => $existing->product->code])
            ->assertUnprocessable()->assertJsonStructure(['fields' => ['product_code']]);
        $this->assertSame($productCount, Product::count());
        $this->assertSame($skuCount, Sku::count());
        $other = Sku::where('id', '!=', $existing->id)->firstOrFail();
        $this->putJson('/api/v1/skus/'.$other->id, ['name' => $other->name, 'sku_code' => $existing->sku_code])
            ->assertUnprocessable()->assertJsonStructure(['fields' => ['sku_code']]);
        $this->assertNotSame($existing->sku_code, $other->refresh()->sku_code);
    }

    public function test_edit_can_keep_own_codes_or_change_to_unused_codes(): void
    {
        $this->authenticate();
        $sku = Sku::with('product')->firstOrFail();
        $this->putJson('/api/v1/skus/'.$sku->id, ['name' => $sku->name, 'sku_code' => $sku->sku_code, 'product_code' => $sku->product->code])->assertOk();
        $this->putJson('/api/v1/skus/'.$sku->id, ['name' => $sku->name, 'sku_code' => 'VK-NEW', 'product_code' => 'SP-NEW'])
            ->assertOk()->assertJsonPath('data.sku_code', 'VK-NEW')->assertJsonPath('data.product.code', 'SP-NEW');
    }
}
