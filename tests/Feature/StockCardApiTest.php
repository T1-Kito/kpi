<?php
namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_card_filters_lot_and_calculates_period_balances(): void
    {
        $this->seed();
        $balance = InventoryBalance::firstOrFail();
        $balance->update(['lot_no' => 'CARD-TEST', 'on_hand' => 30, 'available' => 30]);
        foreach ([['2026-10-01 10:00:00', 10], ['2026-10-02 10:00:00', -3], ['2026-10-03 10:00:00', 5]] as [$date, $quantity]) {
            InventoryTransaction::forceCreate(['tenant_id' => $balance->tenant_id, 'warehouse_id' => $balance->warehouse_id,
                'sku_id' => $balance->sku_id, 'lot_no' => 'CARD-TEST', 'quantity' => $quantity,
                'transaction_type' => $quantity > 0 ? 'receipt' : 'issue', 'source_type' => 'System', 'source_id' => 0,
                'created_at' => $date, 'updated_at' => $date]);
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@vk-kpi.local', 'password' => 'Admin@123'])->json('data.access_token');
        $query = http_build_query(['sku_id' => $balance->sku_id, 'warehouse_id' => $balance->warehouse_id, 'lot_no' => 'CARD-TEST',
            'card' => 1, 'from' => '2026-10-02', 'to' => '2026-10-02']);
        $this->withToken($token)->getJson('/api/v1/inventory-transactions?'.$query)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.summary.opening', 28)
            ->assertJsonPath('meta.summary.out', 3)->assertJsonPath('meta.summary.closing', 25);
        $this->get('/inventory-transactions')->assertOk()->assertSee('data-inventory-mode="transactions"', false);
        $this->withToken($token)->getJson('/api/v1/inventory-transactions?to=2026-10-02')->assertOk();
        $this->withToken($token)->getJson('/api/v1/inventory-transactions?from=2026-10-03&to=2026-10-02')->assertUnprocessable();
    }
}
