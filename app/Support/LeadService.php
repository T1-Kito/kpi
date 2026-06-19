<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeadService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly TaskService $tasks,
        private readonly CodeGenerator $codes,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Lead
    {
        return DB::transaction(function () use ($actor, $data) {
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
            $old = $lead->assigned_to;
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
}
