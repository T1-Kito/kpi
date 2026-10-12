<?php

namespace App\Services\Automation;

use App\Events\BusinessEventOccurred;
use App\Models\BusinessEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

class BusinessEventDispatcher
{
    /** @return array{processed:int,failed:int,unsupported:int,exhausted:int} */
    public function process(int $limit = 100, int $maxAttempts = 5): array
    {
        $result = ['processed' => 0, 'failed' => 0, 'unsupported' => 0, 'exhausted' => 0];
        $supported = array_fill_keys(config('business-events.supported', []), true);
        $staleBefore = now()->subMinutes(10);

        $ids = BusinessEvent::query()
            ->where(function ($query) use ($staleBefore) {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(function ($processing) use ($staleBefore) {
                        $processing->where('status', 'processing')
                            ->where('last_attempted_at', '<=', $staleBefore);
                    });
            })
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->pluck('id');

        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id, $maxAttempts, $staleBefore, $supported, &$result) {
                $event = BusinessEvent::query()->lockForUpdate()->find($id);
                if (! $event || ! in_array($event->status, ['pending', 'failed', 'processing'], true)) {
                    return null;
                }
                if ($event->status === 'processing' && (! $event->last_attempted_at || $event->last_attempted_at->gt($staleBefore))) {
                    return null;
                }
                if ($event->next_attempt_at?->isFuture()) {
                    return null;
                }
                if (! isset($supported[$event->event_type])) {
                    $result['unsupported']++;

                    return null;
                }
                if ($event->retry_count >= $maxAttempts) {
                    $result['exhausted']++;

                    return null;
                }

                $event->update([
                    'status' => 'processing',
                    'retry_count' => $event->retry_count + 1,
                    'last_attempted_at' => now(),
                    'next_attempt_at' => null,
                ]);

                return $event->fresh();
            });

            if (! $event) {
                continue;
            }

            try {
                BusinessEventOccurred::dispatch($event);
                $event->update([
                    'status' => 'processed',
                    'error_message' => null,
                    'processed_at' => now(),
                    'next_attempt_at' => null,
                ]);
                $result['processed']++;
            } catch (Throwable $error) {
                report($error);
                $delayMinutes = min(60, 2 ** max(0, $event->retry_count - 1));
                $event->update([
                    'status' => 'failed',
                    'error_message' => mb_substr($error->getMessage(), 0, 4000),
                    'next_attempt_at' => now()->addMinutes($delayMinutes),
                ]);
                $result['failed']++;
            }
        }

        return $result;
    }
}
