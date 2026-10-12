<?php

namespace Tests\Feature;

use App\Events\BusinessEventOccurred;
use App\Models\BusinessEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    public function test_registered_event_is_dispatched_once_and_acknowledged(): void
    {
        $this->seed();
        Event::fake([BusinessEventOccurred::class]);
        $actor = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $event = BusinessEvent::create([
            'tenant_id' => $actor->tenant_id,
            'event_type' => 'TaskCreated',
            'source_type' => 'Task',
            'source_id' => 10,
            'payload' => ['code' => 'TASK-10'],
            'status' => 'pending',
        ]);

        $this->artisan('events:retry-failed')->assertExitCode(0);

        $event->refresh();
        $this->assertSame('processed', $event->status);
        $this->assertSame(1, $event->retry_count);
        $this->assertNotNull($event->last_attempted_at);
        $this->assertNotNull($event->processed_at);
        Event::assertDispatched(BusinessEventOccurred::class, fn ($published) => $published->businessEvent->is($event));
    }

    public function test_listener_failure_is_retried_later_without_false_success(): void
    {
        $this->seed();
        Event::listen(BusinessEventOccurred::class, function () {
            throw new \RuntimeException('Temporary listener failure');
        });
        $actor = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $event = BusinessEvent::create([
            'tenant_id' => $actor->tenant_id,
            'event_type' => 'TaskCreated',
            'source_type' => 'Task',
            'source_id' => 11,
            'payload' => [],
            'status' => 'pending',
        ]);

        $this->artisan('events:retry-failed')->assertExitCode(1);

        $event->refresh();
        $this->assertSame('failed', $event->status);
        $this->assertSame(1, $event->retry_count);
        $this->assertSame('Temporary listener failure', $event->error_message);
        $this->assertNotNull($event->next_attempt_at);
        $this->assertNull($event->processed_at);
    }

    public function test_active_claim_and_future_backoff_are_not_processed_twice(): void
    {
        $this->seed();
        Event::fake([BusinessEventOccurred::class]);
        $actor = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        foreach ([
            ['status' => 'processing', 'last_attempted_at' => now()],
            ['status' => 'failed', 'next_attempt_at' => now()->addMinute()],
        ] as $state) {
            BusinessEvent::create(array_merge([
                'tenant_id' => $actor->tenant_id,
                'event_type' => 'TaskCreated',
                'source_type' => 'Task',
                'payload' => [],
                'retry_count' => 1,
            ], $state));
        }

        $this->artisan('events:retry-failed')->assertExitCode(0);

        $this->assertSame(0, BusinessEvent::where('status', 'processed')->count());
        Event::assertNotDispatched(BusinessEventOccurred::class);
    }
}
