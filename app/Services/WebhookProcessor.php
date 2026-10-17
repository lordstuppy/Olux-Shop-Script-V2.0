<?php

namespace App\Services;

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Support\ErrorSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies stored webhook events (payment and payout callbacks) and schedules
 * retries with exponential backoff when processing fails for a transient
 * reason (database outage, deadlock, mail transport down, ...).
 */
class WebhookProcessor
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * Takes an event for one processing attempt under a row lock, so the retry
     * job, the sweep and a staff retry never run it at the same time. Returns
     * null when the event is not due.
     *
     * @param  bool  $manual  staff asked: failed events need not wait for their backoff, dead ones are revived
     */
    public function claim(int $eventId, bool $manual = false): ?WebhookEvent
    {
        return DB::transaction(function () use ($eventId, $manual) {
            $event = WebhookEvent::query()->whereKey($eventId)->lockForUpdate()->first();
            $due = match ($event?->status) {
                WebhookEventStatus::Failed => $manual || ! $event->next_attempt_at?->isFuture(),
                WebhookEventStatus::Dead => $manual,
                // Stored, then the process died before a result or a retry was saved.
                WebhookEventStatus::Received => $event->isStale(),
                default => false,
            };
            if (! $due) {
                return null;
            }
            $event->status = WebhookEventStatus::Received;
            $event->save();
            $event->touch();

            return $event;
        });
    }

    public function process(WebhookEvent $event): void
    {
        $event->attempts++;
        try {
            $result = $this->handle($event);
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

    /** @return array{status: string, message: string} */
    private function handle(WebhookEvent $event): array
    {
        $payload = $event->payload;

        return match ($event->provider) {
            'shkeeper' => $this->payments->handleShkeeperNotification($payload),
            'shkeeper-payout' => $this->payout($payload),
            default => ['status' => 'ignored', 'message' => "unknown provider {$event->provider}"],
        };
    }

    /** @return array{status: string, message: string} */
    private function payout(array $payload): array
    {
        $outcome = app(ShkeeperPayoutService::class)->handleResult((string) ($payload['external_id'] ?? ''), (string) ($payload['status'] ?? ''),
            isset($payload['tx_hash']) ? (string) $payload['tx_hash'] : null);

        return ['status' => $outcome === 'processed' ? 'processed' : 'ignored', 'message' => $outcome];
    }

    private function scheduleRetry(WebhookEvent $event, Throwable $e): void
    {
        $backoff = config('shop.webhook_retry_backoff');
        // Staff see this text; SQL and its values stay in the log.
        $event->last_error = ErrorSummary::of($e);

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
            'exception' => $e,
        ]);

        ProcessWebhookEvent::dispatch($event->id)->delay($event->next_attempt_at);
    }
}
