<?php

namespace Tests\Feature;

use App\Models\BusinessEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessEventRetrySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_without_handler_never_claims_business_action_succeeded(): void
    {
        $this->seed();
        $actor = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $event = BusinessEvent::create(['tenant_id' => $actor->tenant_id, 'event_type' => 'UnknownEvent', 'source_type' => 'System', 'source_id' => 1, 'payload' => [], 'status' => 'failed', 'retry_count' => 1, 'error_message' => 'Original failure']);
        $this->artisan('events:retry-failed')->assertExitCode(1);
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertSame('Original failure', $event->fresh()->error_message);
        $this->assertNull($event->fresh()->processed_at);
    }
}
