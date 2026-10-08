<?php

namespace App\Services\ServiceDesk;

use App\Models\ServiceTicket;
use App\Models\Customer;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Support\AuditLogger;
use App\Support\BusinessEventPublisher;
use App\Support\CodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ServiceDeskService
{
    public function __construct(
        private readonly CodeGenerator $codes,
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
    ) {}

    public function createTicket(User $actor, array $data): ServiceTicket
    {
        return DB::transaction(function () use ($actor, $data) {
            $hours = match ($data['priority'] ?? 'normal') { 'urgent' => 4, 'high' => 8, 'low' => 72, default => 24 };
            $ticket = ServiceTicket::create([
                ...$data,
                'tenant_id' => $actor->tenant_id,
                'code' => $this->codes->next('service_tickets', 'code', 'TKT-', fn ($q) => $q->where('tenant_id', $actor->tenant_id)),
                'opened_by' => $actor->id,
                'status' => 'open',
                'response_due_at' => now()->addHours(min($hours, 8)),
                'resolution_due_at' => now()->addHours($hours),
            ]);
            $ticket->worklogs()->create(['user_id' => $actor->id, 'action' => 'opened', 'visibility' => 'internal', 'content' => 'Tạo ticket dịch vụ.', 'worked_at' => now()]);
            $this->audit->record('service_ticket', $ticket->id, 'create_service_ticket', $actor, null, $ticket->toArray());
            $this->events->publish($actor->tenant_id, 'ServiceTicketCreated', 'ServiceTicket', $ticket->id, ['code' => $ticket->code, 'priority' => $ticket->priority]);
            return $ticket;
        });
    }

    public function addWorklog(ServiceTicket $ticket, User $actor, array $data): ServiceTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $data) {
            abort_if(in_array($ticket->status, ['resolved', 'closed', 'cancelled'], true), 422, 'Ticket đã đóng, không thể ghi thêm worklog.');
            $ticket->worklogs()->create([...$data, 'user_id' => $actor->id, 'worked_at' => now()]);
            if (! $ticket->first_responded_at && ($data['visibility'] ?? 'internal') === 'public') $ticket->update(['first_responded_at' => now()]);
            $this->audit->record('service_ticket', $ticket->id, 'add_ticket_worklog', $actor, null, $data);
            return $ticket->refresh();
        });
    }

    public function transitionTicket(ServiceTicket $ticket, User $actor, array $data): ServiceTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $data) {
            $old = $ticket->status;
            $status = $data['status'];
            abort_if(in_array($old, ['closed', 'cancelled'], true), 422, 'Ticket đã kết thúc.');
            abort_if($status === 'resolved' && empty($data['resolution_code']), 422, 'Cần mã xử lý khi hoàn tất ticket.');
            $ticket->update([
                'status' => $status,
                'assignee_id' => $data['assignee_id'] ?? $ticket->assignee_id,
                'resolution_code' => $data['resolution_code'] ?? $ticket->resolution_code,
                'resolution_note' => $data['resolution_note'] ?? $ticket->resolution_note,
                'satisfaction_score' => $data['satisfaction_score'] ?? $ticket->satisfaction_score,
                'resolved_at' => $status === 'resolved' ? now() : $ticket->resolved_at,
            ]);
            $ticket->worklogs()->create(['user_id' => $actor->id, 'action' => 'status_changed', 'visibility' => 'internal', 'content' => "Chuyển trạng thái {$old} → {$status}.", 'worked_at' => now()]);
            $this->audit->record('service_ticket', $ticket->id, 'update_service_ticket', $actor, ['status' => $old], ['status' => $status]);
            $this->events->publish($ticket->tenant_id, 'ServiceTicketStatusChanged', 'ServiceTicket', $ticket->id, ['from' => $old, 'to' => $status]);
            return $ticket->refresh();
        });
    }

    /** @return array<int, WarrantyClaim> */
    public function registerWarranties(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data) {
            $customer = ! empty($data['customer_id']) ? Customer::where('tenant_id', $actor->tenant_id)->findOrFail($data['customer_id']) : Customer::firstOrCreate(
                ['tenant_id' => $actor->tenant_id, 'tax_code' => $data['tax_code'] ?: '__warranty_'.md5($data['customer_name'].$data['customer_phone'])],
                ['code' => $this->codes->next('customers', 'code', 'CUS-', fn ($q) => $q->where('tenant_id', $actor->tenant_id)), 'name' => $data['customer_name'], 'contact_name' => $data['customer_name'], 'phone' => $data['customer_phone'] ?? null, 'email' => $data['customer_email'] ?? null, 'address' => $data['customer_address'] ?? null, 'status' => 'active'],
            );
            $start = Carbon::parse($data['warranty_start_at']);
            $end = $start->copy()->addMonths((int) $data['warranty_months'])->subDay();
            $records = [];
            foreach ($data['serial_numbers'] as $serial) {
                $claim = WarrantyClaim::create([
                    'tenant_id' => $actor->tenant_id, 'customer_id' => $customer->id, 'tax_code' => $data['tax_code'] ?? null, 'customer_name' => $data['customer_name'], 'customer_phone' => $data['customer_phone'] ?? null, 'customer_email' => $data['customer_email'] ?? null, 'customer_address' => $data['customer_address'] ?? null,
                    'serial_number' => $serial, 'product_name' => $data['product_name'], 'purchase_date' => $data['purchase_date'] ?? null, 'warranty_start_at' => $start, 'warranty_months' => $data['warranty_months'], 'warranty_end_at' => $end,
                    'created_by' => $actor->id, 'code' => $this->codes->next('warranty_claims', 'code', 'WAR-', fn ($q) => $q->where('tenant_id', $actor->tenant_id)), 'issue_description' => 'Đăng ký bảo hành', 'warranty_status' => now()->startOfDay()->lte($end) ? 'in_warranty' : 'out_of_warranty', 'status' => 'active',
                ]);
                $this->audit->record('warranty', $claim->id, 'register_warranty', $actor, null, $claim->toArray());
                $records[] = $claim;
            }
            $this->events->publish($actor->tenant_id, 'WarrantyRegistered', 'Warranty', $records[0]->id, ['count' => count($records)]);
            return $records;
        });
    }
}
