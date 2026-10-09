<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeadService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly TaskService $tasks,
        private readonly CodeGenerator $codes,
        private readonly DealService $deals,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Lead
    {
        return DB::transaction(function () use ($actor, $data) {
            $data['assigned_to'] = $data['assigned_to'] ?? app(WorkAssignmentService::class)->pick($actor->tenant_id, 'lead');
            $lead = Lead::create([
                'tenant_id' => $actor->tenant_id,
                'code' => $this->codes->next('leads', 'code', 'LEAD-', fn ($query) => $query->where('tenant_id', $actor->tenant_id)),
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'source' => $data['source'] ?? null,
                'campaign_code' => $data['campaign_code'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'status' => 'new',
            ]);

            $this->audit->record('lead', $lead->id, 'create_lead', $actor, null, $lead->toArray());
            $this->events->publish($actor->tenant_id, 'LeadCreated', 'Lead', $lead->id, ['code' => $lead->code]);

            if ($lead->assigned_to) {
                $this->assign($lead, $actor, (int) $lead->assigned_to);
            }

            return $lead->refresh();
        });
    }

    public function assign(Lead $lead, User $actor, int $salesUserId): Lead
    {
        return DB::transaction(function () use ($lead, $actor, $salesUserId) {
            if ($lead->assigned_to === $salesUserId && $lead->status === 'assigned') return $lead;
            $old = $lead->assigned_to;
            foreach (\App\Models\Task::where('tenant_id', $lead->tenant_id)->where('source_type', 'Lead')->where('source_id', $lead->id)
                ->whereIn('status', ['new', 'in_progress', 'overdue'])->get() as $task) {
                $this->tasks->changeStatus($task, $actor, 'cancelled', 'lead_reassigned');
            }
            $lead->update(['assigned_to' => $salesUserId, 'status' => 'assigned']);

            $this->audit->record('lead', $lead->id, 'assign_lead', $actor, ['assigned_to' => $old], ['assigned_to' => $salesUserId]);
            $this->events->publish($lead->tenant_id, 'LeadAssigned', 'Lead', $lead->id, ['assigned_to' => $salesUserId]);

            $this->tasks->create($actor, [
                'module' => 'sales',
                'task_type' => 'lead_follow_up',
                'priority' => 'normal',
                'title' => 'Chăm sóc khách hàng tiềm năng '.$lead->name,
                'description' => 'Liên hệ khách hàng tiềm năng '.$lead->phone.' và cập nhật trạng thái.',
                'source_type' => 'Lead',
                'source_id' => $lead->id,
                'assignee_id' => $salesUserId,
            ]);

            return $lead->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function qualify(Lead $lead, User $actor, array $data): array
    {
        return DB::transaction(function () use ($lead, $actor, $data) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if ($lead->status === 'qualified') {
                return ['lead' => $lead, 'customer' => Customer::findOrFail($lead->customer_id),
                    'deal' => \App\Models\Deal::where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id)->firstOrFail()];
            }

            $selectedId = $data['customer_id'] ?? $lead->customer_id;
            $customer = $selectedId
                ? Customer::where('tenant_id', $lead->tenant_id)->findOrFail($selectedId)
                : $this->matchingCustomer($lead);

            if ($customer) abort_unless(DataScope::owned(Customer::whereKey($customer->id), $actor, 'sales_owner_id', 'salesOwner')->exists(), 422, 'Khách hàng phù hợp nằm ngoài phạm vi phụ trách. Cần quản lý xác nhận liên kết.');

            if (! $customer) {
                $customer = Customer::create([
                    'tenant_id' => $lead->tenant_id,
                    'code' => $this->codes->next('customers', 'code', 'CUS-', fn ($query) => $query->where('tenant_id', $lead->tenant_id)),
                    'name' => $data['customer_name'] ?? $lead->name,
                    'customer_type' => $data['customer_type'] ?? 'person',
                    'contact_name' => ($data['customer_type'] ?? 'person') === 'organization' ? $lead->name : null,
                    'phone' => $lead->phone,
                    'email' => $lead->email,
                    'sales_owner_id' => $lead->assigned_to ?? $actor->id,
                    'status' => 'active',
                ]);
                $this->audit->record('customer', $customer->id, 'create_customer_from_lead', $actor, null, $customer->toArray());
                $this->events->publish($lead->tenant_id, 'CustomerCreated', 'Customer', $customer->id, ['code' => $customer->code, 'source' => 'lead_qualification']);
            }

            abort_if($customer->merged_into_id || $customer->status !== 'active', 422, 'Khách hàng đã ngừng sử dụng hoặc được gộp. Hãy chọn hồ sơ đang hoạt động.');
            if ($customer->customer_type === 'organization') {
                $contact = $customer->contacts()->get()->first(fn ($contact) =>
                    $contact->name === $lead->name &&
                    self::normalizedPhone($contact->phone) === self::normalizedPhone($lead->phone));
                if (! $contact) {
                    $contact = $customer->contacts()->create([
                        'name' => $lead->name, 'phone' => $lead->phone, 'email' => $lead->email,
                        'is_primary' => ! $customer->contacts()->where('is_primary', true)->exists(),
                    ]);
                    $this->audit->record('customer_contact', $contact->id, 'create_contact_from_lead', $actor, null, $contact->toArray());
                }
                if (!$customer->contacts()->where('is_primary', true)->exists()) $contact->update(['is_primary' => true]);
            }

            $old = $lead->only(['status', 'customer_id']);
            $lead->update(['status' => 'qualified', 'customer_id' => $customer->id]);
            foreach (\App\Models\Task::where('tenant_id', $lead->tenant_id)->where('source_type', 'Lead')->where('source_id', $lead->id)
                ->whereIn('status', ['new', 'in_progress', 'overdue'])->get() as $task) {
                $this->tasks->changeStatus($task, $actor, 'completed', 'lead_qualified');
            }

            $deal = $this->deals->create($actor, [
                'lead_id' => $lead->id,
                'customer_id' => $customer->id,
                'owner_id' => $lead->assigned_to ?? $actor->id,
                'source' => $lead->source,
                'name' => $data['deal_name'] ?? ('Cơ hội từ '.$lead->name),
                'amount' => $data['amount'] ?? 0,
                'expected_close_date' => $data['expected_close_date'] ?? null,
                'next_activity_at' => $data['next_activity_at'] ?? null,
                'stage' => 'qualified',
            ]);

            $followUp = $this->tasks->create($actor, [
                'module' => 'sales', 'task_type' => 'deal_follow_up', 'priority' => 'normal',
                'title' => 'Theo dõi cơ hội '.$deal->code,
                'description' => 'Tiếp tục xử lý nhu cầu của '.$lead->name.' sau khi chuyển đổi khách hàng tiềm năng.',
                'source_type' => 'Deal', 'source_id' => $deal->id,
                'assignee_id' => $deal->owner_id, 'due_at' => $data['next_activity_at'] ?? null,
            ]);
            // Use the task's SLA-derived deadline when no manual date was supplied.
            $deal->update(['next_activity_at' => $followUp->due_at]);

            $this->audit->record('lead', $lead->id, 'qualify_lead', $actor, $old, $lead->fresh()->only(['status', 'customer_id']));
            $this->events->publish($lead->tenant_id, 'LeadQualified', 'Lead', $lead->id, ['customer_id' => $customer->id, 'deal_id' => $deal->id]);

            return ['lead' => $lead->refresh(), 'customer' => $customer->refresh(), 'deal' => $deal];
        });
    }

    private function matchingCustomer(Lead $lead): ?Customer
    {
        $phone = self::normalizedPhone($lead->phone);
        $email = strtolower(trim((string) $lead->email));
        $matches = Customer::where('tenant_id', $lead->tenant_id)->whereNull('merged_into_id')->get()
            ->load('contacts')
            ->filter(fn ($customer) => ($phone !== '' && self::normalizedPhone($customer->phone) === $phone)
                || ($email !== '' && strtolower(trim((string) $customer->email)) === $email)
                || $customer->contacts->contains(fn ($contact) => ($phone !== '' && self::normalizedPhone($contact->phone) === $phone)
                    || ($email !== '' && strtolower(trim((string) $contact->email)) === $email)));
        abort_if($matches->count() > 1, 422, 'Thông tin liên hệ khớp nhiều khách hàng. Cần xử lý trùng hoặc chọn đúng khách hàng trước khi chuyển đổi.');
        return $matches->first();
    }

    private static function normalizedPhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        return str_starts_with($digits, '84') ? '0'.substr($digits, 2) : $digits;
    }
}
