<?php

namespace App\Support;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DealService
{
    private const STAGES = [
        'new' => 10,
        'qualified' => 25,
        'proposal' => 50,
        'negotiation' => 75,
        'won' => 100,
        'lost' => 0,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessEventPublisher $events,
        private readonly CodeGenerator $codes,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Deal
    {
        return DB::transaction(function () use ($actor, $data) {
            $stage = $data['stage'] ?? 'new';
            $stages = self::stages((int) $actor->tenant_id);
            abort_unless(array_key_exists($stage, $stages), 422, 'Giai đoạn đã ngừng sử dụng hoặc không hợp lệ.');
            $type = self::stageType((int) $actor->tenant_id, $stage);
            $deal = Deal::create([
                'tenant_id' => $actor->tenant_id,
                'code' => $this->codes->next('deals', 'code', 'DEAL-', fn ($query) => $query->where('tenant_id', $actor->tenant_id)),
                'lead_id' => $data['lead_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'owner_id' => $data['owner_id'] ?? $actor->id,
                'name' => $data['name'],
                'source' => $data['source'] ?? null,
                'description' => $data['description'] ?? null,
                'amount' => $data['amount'] ?? 0,
                'probability' => $stages[$stage],
                'expected_close_date' => $data['expected_close_date'] ?? null,
                'next_activity_at' => $data['next_activity_at'] ?? null,
                'stage' => $stage,
                'status' => $type,
            ]);
            $deal->stageHistory()->create(['from_stage' => null, 'to_stage' => $stage, 'changed_by' => $actor->id, 'reason' => 'Tạo cơ hội', 'changed_at' => now()]);
            $this->audit->record('deal', $deal->id, 'create_deal', $actor, null, $deal->toArray());
            $this->events->publish($actor->tenant_id, 'DealCreated', 'Deal', $deal->id, ['code' => $deal->code, 'stage' => $stage]);
            return $deal->refresh();
        });
    }

    public function transition(Deal $deal, User $actor, string $stage, ?string $reason, int $version): Deal
    {
        $stages = self::stages((int) $deal->tenant_id);
        abort_if(! array_key_exists($stage, $stages), 422, 'Giai đoạn cơ hội không hợp lệ.');

        return DB::transaction(function () use ($deal, $actor, $stage, $reason, $version, $stages) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $type = self::stageType((int) $deal->tenant_id, $stage);
            abort_if($deal->version !== $version, 409, 'Cơ hội đã được người khác cập nhật. Hãy tải lại trước khi chuyển giai đoạn.');
            abort_if(in_array($deal->status, ['won', 'lost'], true), 422, 'Cơ hội đã kết thúc, không thể chuyển giai đoạn.');
            abort_if($type === 'lost' && ! $reason, 422, 'Cần nhập lý do mất cơ hội.');
            abort_if(in_array($stage, ['proposal', 'negotiation', 'won'], true) && (! $deal->amount || ! $deal->expected_close_date), 422, 'Cần có giá trị dự kiến và ngày dự kiến chốt trước khi chuyển giai đoạn này.');

            $old = $deal->only(['stage', 'status', 'probability', 'version', 'lost_reason']);
            $deal->update([
                'stage' => $stage,
                'status' => $type,
                'probability' => $stages[$stage],
                'lost_reason' => $type === 'lost' ? $reason : null,
                'won_at' => $type === 'won' ? now() : null,
                'lost_at' => $type === 'lost' ? now() : null,
                'version' => $deal->version + 1,
            ]);
            $deal->stageHistory()->create(['from_stage' => $old['stage'], 'to_stage' => $stage, 'changed_by' => $actor->id, 'reason' => $reason, 'changed_at' => now()]);
            $this->audit->record('deal', $deal->id, 'transition_deal', $actor, $old, $deal->fresh()->only(['stage', 'status', 'probability', 'version', 'lost_reason']), null, $reason);
            $this->events->publish($deal->tenant_id, $stage === 'won' ? 'DealWon' : 'DealStageChanged', 'Deal', $deal->id, ['from' => $old['stage'], 'to' => $stage, 'amount' => $deal->amount]);
            return $deal->refresh();
        });
    }

    /** @param array<int, array{sku_id:int,quantity:numeric,unit_price:numeric}> $items */
    public function syncItems(Deal $deal, User $actor, array $items): Deal
    {
        return DB::transaction(function () use ($deal, $actor, $items) {
            $deal->items()->delete();
            foreach ($items as $item) {
                $deal->items()->create([
                    'sku_id' => $item['sku_id'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'],
                    'line_total' => $item['quantity'] * $item['unit_price'],
                ]);
            }
            $amount = $deal->items()->sum('line_total');
            $deal->update(['amount' => $amount]);
            $this->audit->record('deal', $deal->id, 'update_deal_items', $actor, null, ['item_count' => count($items), 'amount' => $amount]);
            return $deal->refresh();
        });
    }

    public static function stages(?int $tenantId = null): array
    {
        if ($tenantId === null) return self::STAGES;
        $query = \App\Models\SalesPipelineStage::where('tenant_id', $tenantId);
        if (! (clone $query)->exists()) return self::STAGES;
        return $query->where('is_active', true)->orderBy('sort_order')->pluck('probability', 'code')->all();
    }

    private static function stageType(int $tenantId, string $stage): string
    {
        return \App\Models\SalesPipelineStage::where('tenant_id', $tenantId)->where('code', $stage)->value('type')
            ?? (in_array($stage, ['won', 'lost'], true) ? $stage : 'open');
    }
}
