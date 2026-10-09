<?php

namespace App\Services;

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies stored webhook events and schedules retries with exponential
 * backoff when processing fails for a transient reason (database outage,
 * deadlock, mail transport down, ...).
 */
class WebhookProcessor
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function process(WebhookEvent $event): void
    {
        $event->attempts++;
        try {
            $result = $this->payments->handleShkeeperNotification($event->payload);
        } catch (Throwable $e) {
            $this->scheduleRetry($event, $e);

            return;
        }

        $event->status = match ($result['status']) {
            'processed' => WebhookEventStatus::Processed,
            'rejected' => WebhookEventStatus::Rejected,
            default => WebhookEventStatus::Ignored,
        };
        $event->last_error = $result['status'] === 'processed' ? null : mb_substr($result['message'], 0, 1000);
        $event->processed_at = now();
        $event->next_attempt_at = null;
        $event->save();
    }

    private function scheduleRetry(WebhookEvent $event, Throwable $e): void
    {
        $backoff = config('shop.webhook_retry_backoff');
        $event->last_error = mb_substr(get_class($e).': '.$e->getMessage(), 0, 1000);

        if ($event->attempts > count($backoff)) {
            $event->status = WebhookEventStatus::Dead;
            $event->next_attempt_at = null;
            $event->save();
            Log::error('Webhook event {event_id} for {external_id} failed permanently after {attempts} attempts: {reason}', [
                'event_id' => $event->id,
                'external_id' => $event->external_id,
                'attempts' => $event->attempts,
                'reason' => $event->last_error,
                'exception' => $e,
            ]);

            return;
        }

        $delay = (int) $backoff[$event->attempts - 1];
        $event->status = WebhookEventStatus::Failed;
        $event->next_attempt_at = now()->addSeconds($delay);
        $event->save();

        Log::warning('Webhook event {event_id} for {external_id} failed on attempt {attempts}; retrying in {delay}s: {reason}', [
            'event_id' => $event->id,
            'external_id' => $event->external_id,
            'attempts' => $event->attempts,
            'delay' => $delay,
            'reason' => $event->last_error,
        ]);

        ProcessWebhookEvent::dispatch($event->id)->delay($event->next_attempt_at);
    }
}
