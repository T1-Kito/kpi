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
            if(!empty($data['assignee_id'])) {
                $assignee=User::where('tenant_id',$actor->tenant_id)->whereKey($data['assignee_id'])->where('is_active',true)->first();
                abort_unless($assignee && $assignee->hasPermission('service.ticket.view'),422,'Người phụ trách phải hoạt động và có quyền xem yêu cầu hỗ trợ.');
            }
            $hours = match ($data['priority'] ?? 'normal') { 'urgent' => 4, 'high' => 8, 'low' => 72, default => 24 };
            $policy=\App\Support\ServiceTicketSla::selected($actor,$data['priority'] ?? 'normal');
            $rules=$policy?->escalation_rules ?? ['clock'=>'continuous','allow_pause'=>false];
            $rules['duration_minutes']=$policy?->duration_minutes ?? $hours*60;
            if ($policy) $rules['warning_before_minutes']=$policy->warning_before_minutes;
            $response=\App\Support\ServiceCalendar::addSeconds(now(),($rules['response_minutes'] ?? min($hours*60,480))*60,$rules);
            $resolution=isset($data['resolution_due_at']) ? Carbon::parse($data['resolution_due_at']) : \App\Support\ServiceCalendar::addSeconds(now(),$rules['duration_minutes']*60,$rules);
            $ticket = ServiceTicket::create([
                ...$data,
                'tenant_id' => $actor->tenant_id,
                'code' => $this->codes->next('service_tickets', 'code', 'TKT-', fn ($q) => $q->where('tenant_id', $actor->tenant_id)),
                'opened_by' => $actor->id,
                'status' => 'open',
                'response_due_at' => $response->min($resolution),
                'resolution_due_at' => $resolution,
            ]);
            $ticket->worklogs()->create(['user_id' => $actor->id, 'action' => 'opened', 'visibility' => 'internal', 'content' => 'Tạo ticket dịch vụ.', 'worked_at' => now()]);
            $ticket->worklogs()->create(['user_id'=>$actor->id,'action'=>'sla_policy','visibility'=>'internal','content'=>json_encode($rules,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'worked_at'=>now()]);
            $this->audit->record('service_ticket', $ticket->id, 'create_service_ticket', $actor, null, $ticket->toArray());
            $this->events->publish($actor->tenant_id, 'ServiceTicketCreated', 'ServiceTicket', $ticket->id, ['code' => $ticket->code, 'priority' => $ticket->priority]);
            return $ticket;
        });
    }

    public function addWorklog(ServiceTicket $ticket, User $actor, array $data): ServiceTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $data) {
            $ticket = ServiceTicket::where('tenant_id',$actor->tenant_id)->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $data['action']='note'; // Clients cannot forge SLA or resolution system history.
            abort_if(in_array($ticket->status, ['closed', 'cancelled'], true), 422, 'Ticket đã đóng, không thể ghi thêm worklog.');
            $ticket->worklogs()->create([...$data, 'user_id' => $actor->id, 'worked_at' => now()]);
            if (! $ticket->first_responded_at && ($data['visibility'] ?? 'internal') === 'public') $ticket->update(['first_responded_at' => now()]);
            $this->audit->record('service_ticket', $ticket->id, 'add_ticket_worklog', $actor, null, $data);
            return $ticket->refresh();
        });
    }

    public function transitionTicket(ServiceTicket $ticket, User $actor, array $data): ServiceTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $data) {
            $ticket = ServiceTicket::where('tenant_id',$actor->tenant_id)->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $old = $ticket->status;
            $status = $data['status'];
            $reopen=$old==='resolved' && $status==='in_progress';
            abort_if(\App\Support\ServiceTicketSla::pausedAt($ticket) && $status!=='pending_customer',422,'Tiếp tục SLA trước khi chuyển trạng thái.');
            $before = $ticket->only(['status','assignee_id','resolution_code','resolution_note','resolved_at','customer_confirmation']);
            $assignee = $data['assignee_id'] ?? $ticket->assignee_id;
            if ($assignee) {
                $handler=User::where('tenant_id',$actor->tenant_id)->whereKey($assignee)->where('is_active',true)->first();
                abort_unless($handler && $handler->hasPermission('service.ticket.view'),422,'Người phụ trách phải hoạt động và có quyền xem yêu cầu hỗ trợ.');
            }
            abort_if(in_array($status,['in_progress','pending_customer','resolved'],true) && !$assignee,422,'Cần phân công người phụ trách trước khi xử lý.');
            abort_if(in_array($old, ['closed', 'cancelled'], true), 422, 'Ticket đã kết thúc.');
            abort_if($status === 'closed' && $old !== 'resolved', 422, 'Cần ghi nhận xử lý hoàn tất trước khi đóng ticket.');
            $resolutionLog=$status==='closed' ? $ticket->worklogs()->where('action','resolved')->max('id') : null;
            abort_if($status === 'closed' && (!$ticket->resolved_at || !$resolutionLog || !$ticket->worklogs()->where('visibility','public')->where('id','>',$resolutionLog)->exists()),422,'Cần ghi nhận đã trả lời khách về kết quả xử lý mới nhất trước khi đóng hồ sơ.');
            abort_if($status === 'cancelled' && !trim($data['resolution_note'] ?? ''),422,'Cần ghi lý do hủy để lưu lịch sử.');
            abort_if($old === 'resolved' && !in_array($status, ['resolved','closed','in_progress'], true), 422, 'Chọn xử lý lại nếu khách chưa đồng ý kết quả.');
            abort_if($reopen && (($data['customer_confirmation'] ?? '')!=='rejected' || !trim($data['resolution_note'] ?? '')),422,'Khách chưa đồng ý: cần ghi rõ lý do để xử lý lại.');
            abort_if($status === 'resolved' && empty($data['resolution_code']), 422, 'Cần mã xử lý khi hoàn tất ticket.');
            abort_if($status === 'resolved' && empty($data['resolution_note']),422,'Cần ghi phương án và kết quả xử lý.');
            abort_if($status === 'resolved' && !$ticket->assignee_id && empty($data['assignee_id']),422,'Cần phân công người xử lý trước khi hoàn tất.');
            $ticket->update([
                'status' => $status,
                'customer_confirmation' => $status === 'closed' ? 'accepted' : ($reopen ? 'rejected' : ($status==='resolved' ? 'not_contacted' : $ticket->customer_confirmation)),
                'assignee_id' => $data['assignee_id'] ?? $ticket->assignee_id,
                'resolution_code' => $data['resolution_code'] ?? $ticket->resolution_code,
                'resolution_note' => $data['resolution_note'] ?? $ticket->resolution_note,
                'satisfaction_score' => $data['satisfaction_score'] ?? $ticket->satisfaction_score,
                'resolved_at' => $reopen ? null : ($status === 'resolved' ? ($ticket->resolved_at ?? now()) : $ticket->resolved_at),
            ]);
            $note = trim($data['resolution_note'] ?? '');
            $ticket->worklogs()->create(['user_id' => $actor->id, 'action' => $reopen ? 'customer_rejected' : ($status==='resolved' ? 'resolved' : 'status_changed'), 'visibility' => 'internal', 'content' => "Chuyển trạng thái {$old} → {$status}.".($note !== '' ? "\n".$note : '').($reopen ? "\nKhách chưa đồng ý. Giữ hạn SLA ban đầu. Mốc xử lý trước: ".($before['resolved_at']?->toIso8601String() ?? 'không có') : '').($status === 'closed' ? "\nNhân viên ghi nhận: khách đồng ý kết quả xử lý." : ''), 'worked_at' => now()]);
            $this->audit->record('service_ticket', $ticket->id, 'update_service_ticket', $actor, $before, $ticket->only(array_keys($before)));
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
            $end = $start->copy()->addMonthsNoOverflow((int) $data['warranty_months'])->subDay();
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
