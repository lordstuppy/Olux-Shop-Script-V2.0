<?php

namespace App\Jobs;

use App\Services\WebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Retries a stored webhook event. The WebhookProcessor decides the next
 * backoff step; this job runs one attempt. Copies (sweep and delayed retry)
 * are harmless: the claim under a row lock lets only one run.
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $eventId) {}

    public function handle(WebhookProcessor $processor): void
    {
        $event = $processor->claim($this->eventId);
        if ($event !== null) {
            $processor->process($event);
        }
    }
}
