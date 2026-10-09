<?php

namespace Tests\Feature;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use App\Services\PaymentService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebhookRetryTest extends TestCase
{
    public function test_transient_failure_is_retried_with_backoff(): void
    {
        $payments = Mockery::mock(PaymentService::class);
        $payments->shouldReceive('handleShkeeperNotification')->once()->andThrow(new RuntimeException('deadlock detected'));
        $payments->shouldReceive('handleShkeeperNotification')->once()->andReturn(['status' => 'processed', 'message' => 'ok']);
        $this->app->instance(PaymentService::class, $payments);

        $this->postShkeeperWebhook($this->paidPayload('6f1b0e0c-1d2a-4b47-9a5e-2f0e2b8c9a10', '1.00'))->assertStatus(202);

        $event = WebhookEvent::firstOrFail();
        $this->assertSame(WebhookEventStatus::Failed, $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertEqualsWithDelta(now()->addSeconds(30)->timestamp, $event->next_attempt_at->timestamp, 2);
        $this->assertStringContainsString('deadlock detected', $event->last_error);

        // Not yet due: the sweeper does nothing.
        $this->artisan('shop:retry-webhooks');
        $this->assertSame(WebhookEventStatus::Failed, $event->fresh()->status);

        $this->travel(31)->seconds();
        $this->artisan('shop:retry-webhooks');
        $event->refresh();
        $this->assertSame(WebhookEventStatus::Processed, $event->status);
        $this->assertSame(2, $event->attempts);
    }

    public function test_event_is_marked_dead_after_all_retries(): void
    {
        $payments = Mockery::mock(PaymentService::class);
        $payments->shouldReceive('handleShkeeperNotification')->andThrow(new RuntimeException('database unavailable'));
        $this->app->instance(PaymentService::class, $payments);

        $this->postShkeeperWebhook($this->paidPayload('6f1b0e0c-1d2a-4b47-9a5e-2f0e2b8c9a10', '1.00'))->assertStatus(202);

        $delays = [];
        foreach (config('shop.webhook_retry_backoff') as $delay) {
            $event = WebhookEvent::firstOrFail();
            $delays[] = $event->next_attempt_at->timestamp - now()->timestamp;
            $this->travel($delay + 1)->seconds();
            $this->artisan('shop:retry-webhooks');
        }

        $event = WebhookEvent::firstOrFail();
        $this->assertSame(WebhookEventStatus::Dead, $event->status);
        $this->assertSame(6, $event->attempts);
        $this->assertSame([30, 120, 600, 1800, 7200], $delays);
    }
}
