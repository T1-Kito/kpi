<?php

namespace App\Support;

use App\Models\BusinessEvent;

class BusinessEventPublisher
{
    /** @param array<string, mixed> $payload */
    public function publish(int $tenantId, string $eventType, string $sourceType, ?int $sourceId = null, array $payload = []): BusinessEvent
    {
        return BusinessEvent::create([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }
}
