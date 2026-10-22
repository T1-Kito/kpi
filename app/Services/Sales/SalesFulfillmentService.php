<?php

namespace App\Services\Sales;

use App\Models\CustomerPayment;
use App\Models\Delivery;
use App\Models\GoodsIssue;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Task;
use App\Support\TaskService;
use App\Support\AuditLogger;
use App\Support\BusinessEventPublisher;
use App\Support\CodeGenerator;
use Illuminate\Support\Facades\DB;

class SalesFulfillmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly CodeGenerator $codes,
        private readonly TaskService $tasks,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function confirmDelivery(SalesOrder $order, User $actor, array $data): Delivery
    {
        return DB::transaction(function () use ($order, $actor, $data) {
            $order = SalesOrder::where('tenant_id', $actor->tenant_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $issue = GoodsIssue::where('tenant_id', $order->tenant_id)
                ->where('sales_order_id', $order->id)
                ->where('status', 'confirmed')
                ->latest('id')
                ->first();

            abort_if(! $issue, 422, 'Đơn bán chưa có phiếu xuất kho đã xác nhận.');
            abort_if(in_array($order->delivery_status, ['delivered_pending_acceptance', 'delivered'], true), 422, 'Đơn bán đã được xác nhận giao hàng.');

            $delivery = Delivery::create([
                'tenant_id' => $order->tenant_id,
                'code' => $this->codes->next('deliveries', 'code', 'DEL-', fn ($query) => $query->where('tenant_id', $order->tenant_id)),
                'sales_order_id' => $order->id,
                'goods_issue_id' => $issue->id,
                'recipient_name' => $data['recipient_name'],
                'recipient_phone' => $data['recipient_phone'] ?? null,
                'delivery_address' => $data['delivery_address'],
                'delivered_at' => $data['delivered_at'] ?? now(),
                'proof_note' => $data['proof_note'] ?? null,
                'confirmed_by' => $actor->id,
                'status' => 'delivered',
                'acceptance_status' => 'pending',
            ]);

            $old = $order->only(['status', 'delivery_status', 'delivered_at']);
            $order->update([
                'status' => 'awaiting_acceptance',
                'delivery_status' => 'delivered_pending_acceptance',
                'delivered_at' => $delivery->delivered_at,
            ]);

            $this->audit->record('delivery', $delivery->id, 'confirm_delivery', $actor, null, $delivery->toArray());
            $this->audit->record('sales_order', $order->id, 'mark_sales_order_delivered', $actor, $old, $order->fresh()->only(['status', 'delivery_status', 'delivered_at']));
            $this->events->publish($order->tenant_id, 'DeliveryConfirmed', 'Delivery', $delivery->id, ['sales_order_id' => $order->id]);
            $this->completeWork($actor, 'SalesOrder', $order->id, 'delivery_confirmation', 'delivery_confirmed');
            $this->tasks->create($actor, [
                'module' => 'sales',
                'task_type' => 'delivery_acceptance',
                'priority' => 'high',
                'title' => 'Nghiệm thu và bàn giao đơn '.$order->code,
                'description' => 'Xác nhận kết quả nghiệm thu với khách hàng; ghi nhận đầy đủ người nghiệm thu và sai lệch nếu có.',
                'source_type' => 'SalesOrder',
                'source_id' => $order->id,
                'assignee_id' => $order->sales_owner_id,
            ]);

            return $delivery->load('goodsIssue:id,code');
        });
    }

    /** @param array<string, mixed> $data */
    public function confirmAcceptance(Delivery $delivery, User $actor, array $data): Delivery
    {
        return DB::transaction(function () use ($delivery, $actor, $data) {
            $delivery = Delivery::where('tenant_id', $actor->tenant_id)->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $order = SalesOrder::where('tenant_id', $actor->tenant_id)->whereKey($delivery->sales_order_id)->lockForUpdate()->firstOrFail();
            abort_if($delivery->acceptance_status !== 'pending', 422, 'Phiếu giao hàng đã được ghi nhận kết quả nghiệm thu.');
            abort_if($order->delivery_status !== 'delivered_pending_acceptance', 422, 'Đơn bán không ở trạng thái chờ nghiệm thu.');

            $result = $data['acceptance_status'];
            abort_if($result === 'rejected' && blank($data['discrepancy_note'] ?? null), 422, 'Vui lòng ghi rõ sai lệch hoặc lý do không nghiệm thu.');
            $checklistPassed = ($data['quantity_accepted'] ?? true)
                && ($data['condition_accepted'] ?? true)
                && ($data['documents_accepted'] ?? true);
            abort_if($result === 'accepted' && ! $checklistPassed, 422, 'Kết quả “Đạt toàn bộ” yêu cầu tất cả danh mục kiểm tra đều đạt.');
            $acceptanceFile = [];
            if (! empty($data['acceptance_file'])) {
                $file = $data['acceptance_file'];
                $acceptanceFile = [
                    'acceptance_file_path' => $file->store('delivery-acceptances/'.$actor->tenant_id, 'local'),
                    'acceptance_file_name' => $file->getClientOriginalName(),
                ];
            }
            $old = $delivery->toArray();
            $delivery->update([
                'acceptance_status' => $result,
                'accepted_at' => $data['accepted_at'] ?? now(),
                'accepted_by_name' => $data['accepted_by_name'],
                'accepted_by_title' => $data['accepted_by_title'] ?? null,
                'quantity_accepted' => $data['quantity_accepted'] ?? true,
                'condition_accepted' => $data['condition_accepted'] ?? true,
                'documents_accepted' => $data['documents_accepted'] ?? true,
                'acceptance_note' => $data['acceptance_note'] ?? null,
                'discrepancy_note' => $data['discrepancy_note'] ?? null,
                'acceptance_confirmed_by' => $actor->id,
                ...$acceptanceFile,
            ]);

            $accepted = in_array($result, ['accepted', 'accepted_with_notes'], true);
            $oldOrder = $order->only(['status', 'delivery_status']);
            $order->update([
                'status' => $accepted ? 'delivered' : 'awaiting_delivery',
                'delivery_status' => $accepted ? 'delivered' : 'acceptance_rejected',
            ]);

            $this->audit->record('delivery', $delivery->id, 'confirm_delivery_acceptance', $actor, $old, $delivery->fresh()->toArray());
            $this->audit->record('sales_order', $order->id, 'update_delivery_acceptance', $actor, $oldOrder, $order->fresh()->only(['status', 'delivery_status']));
            $this->events->publish($order->tenant_id, 'DeliveryAcceptanceConfirmed', 'Delivery', $delivery->id, ['sales_order_id' => $order->id, 'result' => $result]);

            if ($accepted) {
                $this->completeWork($actor, 'SalesOrder', $order->id, 'delivery_acceptance', 'customer_accepted');
            } else {
                $this->completeWork($actor, 'SalesOrder', $order->id, 'delivery_acceptance', 'customer_rejected');
                $this->tasks->create($actor, [
                    'module' => 'sales', 'task_type' => 'delivery_confirmation', 'priority' => 'urgent',
                    'title' => 'Xử lý sai lệch giao hàng '.$order->code,
                    'description' => $data['discrepancy_note'], 'source_type' => 'SalesOrder', 'source_id' => $order->id,
                    'assignee_id' => $order->sales_owner_id,
                ]);
            }

            return $delivery->fresh()->load(['goodsIssue:id,code', 'acceptanceConfirmedBy:id,name,email']);
        });
    }

    /** @param array<string, mixed> $data */
    public function issueInvoice(SalesOrder $order, User $actor, array $data): SalesInvoice
    {
        return DB::transaction(function () use ($order, $actor, $data) {
            $order = SalesOrder::where('tenant_id', $actor->tenant_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($order->delivery_status !== 'delivered', 422, 'Chỉ xuất hóa đơn sau khi khách đã nhận hàng.');

            $existing = SalesInvoice::where('tenant_id', $order->tenant_id)
                ->where('sales_order_id', $order->id)
                ->first();
            abort_if($existing, 422, 'Đơn bán đã có hóa đơn.');

            $invoice = SalesInvoice::create([
                'tenant_id' => $order->tenant_id,
                'code' => $this->codes->next('sales_invoices', 'code', 'INV-', fn ($query) => $query->where('tenant_id', $order->tenant_id)),
                'sales_order_id' => $order->id,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'issued_at' => now(),
                'tax_invoice_symbol' => $data['tax_invoice_symbol'] ?? null,
                'tax_invoice_no' => $data['tax_invoice_no'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'subtotal_amount' => $order->subtotal_amount,
                'tax_amount' => $order->tax_amount,
                'total_amount' => $order->total_amount,
                'paid_amount' => 0,
                'balance_amount' => $order->total_amount,
                'issued_by' => $actor->id,
                'status' => 'issued',
                'note' => $data['note'] ?? null,
            ]);

            $order->update(['status' => 'invoiced', 'payment_status' => 'unpaid']);
            $this->audit->record('sales_invoice', $invoice->id, 'issue_sales_invoice', $actor, null, $invoice->toArray());
            $this->events->publish($order->tenant_id, 'SalesInvoiceIssued', 'SalesInvoice', $invoice->id, ['sales_order_id' => $order->id]);

            return $invoice;
        });
    }

    /** @param array<string, mixed> $data */
    public function recordPayment(SalesInvoice $invoice, User $actor, array $data): CustomerPayment
    {
        return DB::transaction(function () use ($invoice, $actor, $data) {
            $invoice = SalesInvoice::where('tenant_id', $actor->tenant_id)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true), 422, 'Hóa đơn không cho phép ghi nhận thanh toán.');
            $amount = bcadd((string) $data['amount'], '0', 2);
            $balance = (string) $invoice->balance_amount;
            $reference = trim((string) ($data['reference_no'] ?? ''));
            $key = trim((string)($data['idempotency_key'] ?? ''));
            if (($reference === '' && $key === '') || strlen($key)>120) {
                throw \Illuminate\Validation\ValidationException::withMessages(['reference_no'=>'Cần mã tham chiếu hoặc mã chống gửi lặp để ghi nhận khoản thu.']);
            }
            $operation = 'payment:'.$invoice->id;
            $payloadHash = hash('sha256',json_encode([$amount,$reference,$data['payment_method'] ?? 'bank_transfer',$data['paid_at'] ?? null,$data['note'] ?? null]));
            if ($key !== '') {
                $previous = DB::table('request_replays')->where('user_id',$actor->id)->where('operation',$operation)->where('request_key',$key)->first();
                if ($previous) {
                    abort_if($previous->payload_hash !== $payloadHash,409,'Mã gửi đã dùng cho khoản thu khác.');
                    $id=json_decode($previous->response_body,true)['id'];
                    return CustomerPayment::where('tenant_id',$actor->tenant_id)->where('sales_invoice_id',$invoice->id)->findOrFail($id);
                }
            }
            if ($reference !== '') {
                $existing = CustomerPayment::where('tenant_id', $actor->tenant_id)->where('sales_invoice_id', $invoice->id)->where('reference_no', $reference)->first();
                if ($existing) {
                    abort_if(bccomp((string) $existing->amount, $amount, 2) !== 0 || $existing->payment_method !== ($data['payment_method'] ?? 'bank_transfer'), 409, 'Mã tham chiếu đã dùng cho khoản thu khác.');
                    if ($key !== '') $this->savePaymentReplay($actor->id,$operation,$key,$payloadHash,$existing->id);
                    return $existing;
                }
            }

            abort_if(bccomp($balance, '0', 2) <= 0, 422, 'Hóa đơn đã được thanh toán đủ.');
            abort_if(bccomp($amount, '0', 2) <= 0 || bccomp($amount, $balance, 2) > 0, 422, 'Số tiền thanh toán phải lớn hơn 0 và không vượt số còn phải thu.');

            $payment = CustomerPayment::create([
                'tenant_id' => $invoice->tenant_id,
                'code' => $this->codes->next('customer_payments', 'code', 'PAY-', fn ($query) => $query->where('tenant_id', $invoice->tenant_id)),
                'sales_invoice_id' => $invoice->id,
                'sales_order_id' => $invoice->sales_order_id,
                'amount' => $amount,
                'paid_at' => $data['paid_at'] ?? now(),
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'reference_no' => $reference !== '' ? $reference : null,
                'note' => $data['note'] ?? null,
                'received_by' => $actor->id,
            ]);

            $paid = bcadd((string) $invoice->paid_amount, $amount, 2);
            $remaining = bcsub((string) $invoice->total_amount, $paid, 2);
            $invoiceStatus = bccomp($remaining, '0', 2) === 0 ? 'paid' : 'partially_paid';
            $invoice->update([
                'paid_amount' => $paid,
                'balance_amount' => $remaining,
                'status' => $invoiceStatus,
            ]);

            $order = SalesOrder::where('tenant_id', $invoice->tenant_id)->findOrFail($invoice->sales_order_id);
            $old = $order->only(['status', 'payment_status', 'completed_at']);
            $order->update([
                'payment_status' => $invoiceStatus === 'paid' ? 'paid' : 'partially_paid',
                'status' => $invoiceStatus === 'paid' ? 'completed' : 'invoiced',
                'completed_at' => $invoiceStatus === 'paid' ? now() : null,
            ]);

            $this->audit->record('customer_payment', $payment->id, 'record_customer_payment', $actor, null, $payment->toArray());
            $this->audit->record('sales_order', $order->id, 'update_sales_order_payment', $actor, $old, $order->fresh()->only(['status', 'payment_status', 'completed_at']));
            $this->events->publish($invoice->tenant_id, 'CustomerPaymentRecorded', 'CustomerPayment', $payment->id, [
                'sales_order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'balance_amount' => $remaining,
            ]);
            if ($invoiceStatus === 'paid') {
                $this->completeWork($actor, 'SalesInvoice', $invoice->id, 'payment_follow_up', 'invoice_paid');
                $this->completeWork($actor, 'SalesOrder', $order->id, 'payment_follow_up', 'invoice_paid');
            }

            if ($key !== '') $this->savePaymentReplay($actor->id,$operation,$key,$payloadHash,$payment->id);
            return $payment;
        });
    }

    private function savePaymentReplay(int $userId,string $operation,string $key,string $hash,int $paymentId): void
    {
        DB::table('request_replays')->insert(['user_id'=>$userId,'operation'=>$operation,'request_key'=>$key,'payload_hash'=>$hash,'response_body'=>json_encode(['id'=>$paymentId]),'response_status'=>201,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function completeWork(User $actor, string $source, int $id, string $type, string $reason): void
    {
        foreach (Task::where('tenant_id', $actor->tenant_id)->where('source_type', $source)->where('source_id', $id)
            ->where('task_type', $type)->whereIn('status', ['new', 'in_progress', 'overdue'])->get() as $task) {
            $this->tasks->changeStatus($task, $actor, 'completed', $reason);
        }
    }
}
