<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    /** @param array<string, mixed>|null $oldValue @param array<string, mixed>|null $newValue */
    public function record(
        string $entityType,
        ?int $entityId,
        string $action,
        ?User $user = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?Request $request = null,
        ?string $reason = null,
    ): AuditLog {
        return AuditLog::create([
            'tenant_id' => $user?->tenant_id,
            'user_id' => $user?->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'reason' => $reason,
        ]);
    }
}
