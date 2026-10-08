<?php

namespace Tests\Feature;

use App\Models\{Customer, CustomerPayment, SalesInvoice, SalesOrder, User};
use App\Services\Sales\SalesFulfillmentService;
use App\Support\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PaymentRepeatSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_reference_is_repeat_safe_and_full_payment_closes_follow_up(): void
    {
        $this->seed();
        $actor = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $order = SalesOrder::create(['tenant_id' => $actor->tenant_id, 'code' => 'PAYMENT-SAFE', 'customer_id' => Customer::firstOrFail()->id, 'sales_owner_id' => $actor->id, 'status' => 'invoiced', 'delivery_status' => 'delivered', 'total_amount' => '100.30']);
        $invoice = SalesInvoice::create(['tenant_id' => $actor->tenant_id, 'code' => 'INV-SAFE', 'sales_order_id' => $order->id, 'invoice_date' => now(), 'subtotal_amount' => '100.30', 'tax_amount' => 0, 'total_amount' => '100.30', 'paid_amount' => 0, 'balance_amount' => '100.30', 'status' => 'issued']);
        $task = app(TaskService::class)->create($actor, ['title' => 'Collect payment', 'task_type' => 'payment_follow_up', 'source_type' => 'SalesInvoice', 'source_id' => $invoice->id]);
        $service = app(SalesFulfillmentService::class);
        $first = $service->recordPayment($invoice, $actor, ['amount' => '30.10', 'reference_no' => ' BANK-ONE ']);
        $retry = $service->recordPayment($invoice, $actor, ['amount' => '30.10', 'reference_no' => 'BANK-ONE']);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(0, bccomp('70.20', (string) $invoice->fresh()->balance_amount, 2));
        try {
            $service->recordPayment($invoice, $actor, ['amount' => '40.10', 'reference_no' => 'BANK-ONE']);
            $this->fail('Conflicting reference should be rejected.');
        } catch (HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        $service->recordPayment($invoice, $actor, ['amount' => '70.20', 'reference_no' => 'BANK-TWO']);
        $this->assertSame(0, bccomp('0.00', (string) $invoice->fresh()->balance_amount, 2));
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(2, CustomerPayment::where('sales_invoice_id', $invoice->id)->count());
        $this->assertSame($first->id, $service->recordPayment($invoice, $actor, ['amount' => '30.10', 'reference_no' => 'BANK-ONE'])->id);
    }
}
