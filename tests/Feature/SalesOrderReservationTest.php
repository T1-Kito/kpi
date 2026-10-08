<?php

namespace Tests\Feature;

use App\Models\{Customer, InventoryBalance, SalesOrder, Sku, User};
use App\Support\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_spans_lots_and_repeated_confirmation_does_not_reserve_twice(): void
    {
        $this->seed();
        $actor = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $sku = Sku::where('sku_code', 'SKU-PRN-001')->firstOrFail();
        $balance = InventoryBalance::where('sku_id', $sku->id)->firstOrFail();
        $balance->update(['on_hand' => 2, 'available' => 2, 'reserved' => 0]);
        $second = InventoryBalance::create(['tenant_id' => $actor->tenant_id, 'warehouse_id' => $balance->warehouse_id, 'sku_id' => $sku->id, 'lot_no' => 'SECOND', 'on_hand' => 3, 'available' => 3, 'reserved' => 0]);
        $order = SalesOrder::create(['tenant_id' => $actor->tenant_id, 'code' => 'RESERVE-TEST', 'customer_id' => Customer::firstOrFail()->id, 'sales_owner_id' => $actor->id, 'status' => 'draft', 'stock_status' => 'unchecked', 'total_amount' => 0]);
        $order->items()->create(['sku_id' => $sku->id, 'quantity' => 4, 'unit_price' => 0, 'line_total' => 0]);
        $service = app(SalesOrderService::class);
        $this->assertSame('reserved', $service->confirm($order, $actor)->stock_status);
        $this->assertEquals(2, $balance->fresh()->reserved);
        $this->assertEquals(2, $second->fresh()->reserved);
        $service->confirm($order, $actor);
        $this->assertEquals(1, InventoryBalance::where('sku_id', $sku->id)->sum('available'));
        $this->assertSame(1, \App\Models\Task::where('source_type', 'SalesOrder')->where('source_id', $order->id)->where('task_type', 'warehouse_issue')->count());
        $this->assertSame(1, \App\Models\GoodsIssue::where('sales_order_id', $order->id)->count());
        $this->assertSame('draft', \App\Models\GoodsIssue::where('sales_order_id', $order->id)->firstOrFail()->status);
        $this->assertEquals(5, InventoryBalance::where('sku_id', $sku->id)->sum('on_hand'));
    }

    public function test_confirmation_cannot_access_another_sales_owners_order(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $order = SalesOrder::create(['tenant_id' => $admin->tenant_id, 'code' => 'PRIVATE-CONFIRM', 'customer_id' => Customer::firstOrFail()->id, 'sales_owner_id' => $admin->id, 'status' => 'draft', 'stock_status' => 'unchecked', 'total_amount' => 0]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'sales@vk-kpi.local', 'password' => 'Admin@123'])->json('data.access_token');
        $this->withToken($token)->postJson("/api/v1/sales-orders/{$order->id}/confirm", [])->assertNotFound();
        $this->assertSame('draft', $order->fresh()->status);
    }
}
