<?php

namespace App\Jobs;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use App\Services\WebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Retries a stored webhook event. The WebhookProcessor decides the next
 * backoff step; this job runs one attempt.
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $eventId) {}

    public function handle(WebhookProcessor $processor): void
    {
        $event = DB::transaction(function () {
            $event = WebhookEvent::query()->whereKey($this->eventId)->lockForUpdate()->first();
            // Only failed events whose retry time has come; the sweeper and
            // this job may both pick the same event.
            if ($event === null || $event->status !== WebhookEventStatus::Failed || $event->next_attempt_at?->isFuture()) {
                return null;
            }
            $event->status = WebhookEventStatus::Received;
            $event->save();

            return $event;
        });

        if ($event !== null) {
            $processor->process($event);
        }
    }
}
