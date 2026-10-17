<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Enums\UserRole;
use App\Enums\WebhookEventStatus;
use App\Jobs\DeliverOrder;
use App\Jobs\GenerateInvoicePdf;
use App\Jobs\ProcessWebhookEvent;
use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Notifier;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Shkeeper\ShkeeperClient;
use App\Services\ShkeeperPayoutService;
use App\Services\WebhookProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Hardening of the webhook and queue retry paths (second security-audit run,
 * replay/retry unit): nothing that was stored may be left without a retry,
 * and failures must not end up silently "handled".
 */
class WebhookQueueHardeningTest extends TestCase
{
    private function pendingCryptoOrder(): Order
    {
        $this->fakeShkeeper();
        $product = $this->instantProductWithFile(['price_minor' => 2500]);
        $order = app(OrderService::class)->createFromCart(User::factory()->create(), [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->startShkeeperPayment($order, 'BTC');

        return $order;
    }

    private function postPayoutCallback(array $payload, int $timestamp): TestResponse
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/webhooks/shkeeper/payouts', [], [], [], $this->transformHeadersToServerVars(
            ShkeeperClient::signatureHeaders($raw, 'test-webhook-secret', $timestamp) + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $raw);
    }

    public function test_wq1_an_event_left_in_received_is_picked_up_by_the_retry_sweep(): void
    {
        $order = $this->pendingCryptoOrder();
        // Stored, then the process died before the result (or the retry bookkeeping) was saved.
        $event = WebhookEvent::create(['provider' => 'shkeeper', 'event_key' => hash('sha256', 'x'), 'external_id' => $order->public_id,
            'payload' => $this->paidPayload($order->public_id, '25.00'), 'status' => WebhookEventStatus::Received]);
        $this->artisan('shop:retry-webhooks')->assertSuccessful();
        $this->assertSame(WebhookEventStatus::Received, $event->fresh()->status, 'an event still being processed must not be taken over');

        $this->travel(11)->minutes();
        $this->artisan('shop:retry-webhooks')->assertSuccessful();
        $this->assertSame(WebhookEventStatus::Processed, $event->fresh()->status);
        $this->assertTrue($order->fresh()->status->isPaidState());
        $this->assertSame(0, WebhookEvent::needsAttention()->count());
    }

    public function test_wq1_staff_can_retry_a_stale_received_event_and_it_counts_as_needing_attention(): void
    {
        $order = $this->pendingCryptoOrder();
        $event = WebhookEvent::create(['provider' => 'shkeeper', 'event_key' => hash('sha256', 'y'), 'external_id' => $order->public_id,
            'payload' => $this->paidPayload($order->public_id, '25.00'), 'status' => WebhookEventStatus::Received]);
        $this->assertSame(0, WebhookEvent::needsAttention()->count());
        $this->travel(11)->minutes();
        $this->assertSame(1, WebhookEvent::needsAttention()->count());

        $this->actingAs(User::factory()->admin()->create())->postForm(route('admin.webhooks.retry', $event))->assertSessionHas('success');
        $this->assertSame(WebhookEventStatus::Processed, $event->fresh()->status);
    }

    public function test_wq2_a_failed_payout_callback_is_retried_instead_of_dropped(): void
    {
        $calls = 0;
        $mock = Mockery::mock(ShkeeperPayoutService::class);
        $mock->shouldReceive('handleResult')->andReturnUsing(function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('database went away');
            }

            return 'processed';
        });
        $this->app->instance(ShkeeperPayoutService::class, $mock);
        $payload = ['external_id' => 'payout-5', 'status' => 'SUCCESS', 'tx_hash' => 'abc'];

        // Stored safely, so Shkeeper gets 202 and the shop retries on its own.
        $this->postPayoutCallback($payload, time())->assertStatus(202);
        $event = WebhookEvent::query()->sole();
        $this->assertSame(WebhookEventStatus::Failed, $event->status);
        $this->assertNotNull($event->next_attempt_at);

        $this->travel(1)->minutes();
        ProcessWebhookEvent::dispatchSync($event->id);
        $this->assertSame(2, $calls);
        $this->assertSame(WebhookEventStatus::Processed, $event->fresh()->status);
    }

    public function test_wq2_payout_events_reach_the_payout_service_and_unknown_providers_nothing(): void
    {
        $seller = User::factory()->seller()->create();
        SellerProfile::create(['user_id' => $seller->id, 'display_name' => 'S', 'payout_currency' => 'USD', 'payout_crypto' => 'BTC',
            'payout_address' => 'bc1qsellerpayoutaddress', 'status' => 'approved']);
        $payout = Payout::create(['seller_id' => $seller->id, 'amount_minor' => 5000, 'currency' => 'USD', 'destination' => 'bc1qsellerpayoutaddress',
            'status' => PayoutStatus::Processing, 'provider' => 'shkeeper', 'crypto' => 'BTC', 'attempt' => 1, 'provider_reference' => 'task-1', 'processed_at' => now()]);
        $event = WebhookEvent::create(['provider' => 'shkeeper-payout', 'event_key' => hash('sha256', 'p'), 'external_id' => $payout->externalId(),
            'payload' => ['external_id' => $payout->externalId(), 'status' => 'SUCCESS', 'tx_hash' => 'tx-9'], 'status' => WebhookEventStatus::Received]);
        app(WebhookProcessor::class)->process($event);
        $this->assertSame(WebhookEventStatus::Processed, $event->fresh()->status);
        $this->assertSame(PayoutStatus::Paid, $payout->fresh()->status);

        $odd = WebhookEvent::create(['provider' => 'somewhere-else', 'event_key' => hash('sha256', 'o'), 'external_id' => 'x',
            'payload' => ['external_id' => 'x'], 'status' => WebhookEventStatus::Received]);
        app(WebhookProcessor::class)->process($odd);
        $this->assertSame(WebhookEventStatus::Ignored, $odd->fresh()->status);
    }

    public function test_wq3_the_paid_email_is_sent_once_and_not_lost_when_sending_fails(): void
    {
        Mail::fake();
        $buyer = User::factory()->withBalance(2500)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$this->instantProductWithFile()->id => 1], 'USD', (string) Str::uuid());
        Queue::fake([DeliverOrder::class]);
        app(PaymentService::class)->payWithBalance($order, $buyer);

        // The "order paid" email fails once (mail transport down); other mail goes out.
        $real = app(Notifier::class);
        $failed = false;
        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('send')->andReturnUsing(function ($user, $mail) use ($real, &$failed) {
            if ($mail instanceof OrderPaidMail && ! $failed) {
                $failed = true;
                throw new RuntimeException('mail transport down');
            }
            $real->send($user, $mail);
        });
        $this->app->instance(Notifier::class, $notifier);
        // Run the job directly: the payment's own copy is held back by Queue::fake.
        $run = fn () => app()->call([new DeliverOrder($order->id), 'handle']);
        try {
            $run();
        } catch (RuntimeException) {
        }
        $this->assertTrue($failed);
        $this->assertNull($order->fresh()->paid_notice_sent_at);

        $run();
        $run();
        Mail::assertQueued(OrderPaidMail::class, 1);
        $this->assertNotNull($order->fresh()->paid_notice_sent_at);
    }

    public function test_wq3_a_paid_order_whose_jobs_were_lost_still_gets_its_paid_email(): void
    {
        Mail::fake();
        $seller = User::factory()->seller()->create();
        $manual = $this->instantProductWithFile(['seller_id' => $seller->id]);
        $manual->forceFill(['delivery_type' => 'manual'])->save();
        $buyer = User::factory()->withBalance(2500)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$manual->id => 1], 'USD', (string) Str::uuid());
        // The delivery job was never queued (crash between commit and dispatch).
        Queue::fake([DeliverOrder::class, GenerateInvoicePdf::class]);
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        // Forget the jobs the payment queued: they are the ones that got lost.
        Queue::fake([DeliverOrder::class, GenerateInvoicePdf::class]);

        $this->travel(5)->minutes();
        $this->artisan('shop:recover-stuck-orders')->assertSuccessful();
        Queue::assertPushed(DeliverOrder::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_wq4_the_status_poll_reaches_every_unresolved_payment_over_successive_runs(): void
    {
        $this->fakeShkeeper();
        Http::fake(['shkeeper.test/api/v1/invoices/*' => Http::response(['status' => 'success', 'invoices' => []])]);
        $order = $this->pendingCryptoOrder();
        $oldest = Payment::query()->where('order_id', $order->id)->sole();
        // 200 newer unresolved payments (abandoned invoices).
        for ($i = 0; $i < 200; $i++) {
            $o = Order::query()->create(Order::query()->whereKey($order->id)->first()->replicate(['public_id', 'idempotency_key'])->forceFill([
                'public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()])->getAttributes());
            Payment::query()->create($oldest->replicate(['order_id', 'provider_reference'])->forceFill([
                'order_id' => $o->id, 'provider_reference' => 'inv-'.$i, 'created_at' => now()->addSecond()])->getAttributes());
        }
        $this->travel(5)->minutes();

        app(PaymentService::class)->reconcilePending();
        app(PaymentService::class)->reconcilePending();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/invoices/'.$order->public_id));
    }

    public function test_wq8_stored_errors_and_the_failed_jobs_list_leave_out_sql_and_its_values(): void
    {
        $order = $this->pendingCryptoOrder();
        $payments = Mockery::mock(PaymentService::class);
        $payments->shouldReceive('handleShkeeperNotification')->andThrow(new QueryException('pgsql', 'update orders set note = ? where id = 7', ['secret-binding-value'],
            new PDOException('SQLSTATE[08006]: connection failure')));
        $this->app->instance(PaymentService::class, $payments);
        $event = WebhookEvent::create(['provider' => 'shkeeper', 'event_key' => hash('sha256', 'q'), 'external_id' => $order->public_id,
            'payload' => $this->paidPayload($order->public_id, '25.00'), 'status' => WebhookEventStatus::Received]);
        app(WebhookProcessor::class)->process($event);

        $error = (string) $event->fresh()->last_error;
        $this->assertStringContainsString('QueryException', $error);
        $this->assertStringContainsString('08006', $error);
        $this->assertStringNotContainsString('secret-binding-value', $error);
        $this->assertStringNotContainsString('update orders', $error);

        \DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\DeliverOrder']),
            'exception' => "Illuminate\\Database\\QueryException: SQLSTATE[23505]: Unique violation (Connection: pgsql, SQL: insert into invoices values ('secret-binding-value')) in /app/x.php:1\nStack", 'failed_at' => now()]);
        $this->actingAs(User::factory()->staff(UserRole::Manager)->create())->get(route('admin.health'))->assertOk()
            ->assertSee('23505')->assertDontSee('secret-binding-value');
    }
}
