<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookEventStatus;
use App\Exceptions\UserFacingException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductLicenseKey;
use App\Models\SellerLedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    private function pendingShkeeperOrder(int $price = 2500, string $currency = 'USD', ?Product $product = null): Order
    {
        $this->fakeShkeeper();
        $product ??= $this->instantProductWithFile(['price_minor' => $price, 'currency' => $currency]);
        $order = app(OrderService::class)->createFromCart(User::factory()->create(['currency' => $currency]), [$product->id => 1], $currency, (string) Str::uuid());
        app(PaymentService::class)->startShkeeperPayment($order, 'BTC');

        return $order;
    }

    public function test_replayed_webhook_delivers_only_once(): void
    {
        $product = Product::factory()->price(2500)->create();
        foreach (['KEY-AAA', 'KEY-BBB'] as $key) {
            $product->licenseKeys()->create(['key_encrypted' => $key, 'key_fingerprint' => hash('sha256', $key)]);
        }
        $order = $this->pendingShkeeperOrder(product: $product);
        $payload = $this->paidPayload($order->public_id, '25.00');

        $this->postShkeeperWebhook($payload)->assertStatus(202)->assertJson(['status' => 'processed']);
        // Exact replay (same body, new timestamp and signature).
        $this->postShkeeperWebhook($payload, timestamp: time() + 1)->assertStatus(202);
        // Shkeeper re-sends a callback per transaction, so a different body for the same invoice arrives too.
        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '25.00', extra: ['transactions' => [['txid' => 'tx-2']]]))
            ->assertStatus(202)->assertJson(['status' => 'ignored']);

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame(1, $order->items()->whereNotNull('delivered_payload')->count());
        $this->assertSame(['KEY-AAA'], $order->items()->first()->delivered_payload['license_keys']);
        $this->assertSame(1, ProductLicenseKey::whereNotNull('order_item_id')->count());
        $this->assertSame(2, SellerLedgerEntry::count());
        $this->assertSame(2, WebhookEvent::count());
    }

    public function test_unsigned_and_mismatched_requests_get_401(): void
    {
        $order = $this->pendingShkeeperOrder();
        $raw = json_encode($this->paidPayload($order->public_id, '25.00'));

        $this->call('POST', '/webhooks/shkeeper', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertStatus(401);
        $this->postShkeeperWebhook([], secret: 'wrong-secret', raw: $raw)->assertStatus(401);
        $this->postShkeeperWebhook([], timestamp: time() - 3600, raw: $raw)->assertStatus(401);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_amount_mismatch_does_not_mark_paid(): void
    {
        $order = $this->pendingShkeeperOrder(2500, 'EUR');

        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '24.90', 'EUR'))->assertStatus(202)->assertJson(['status' => 'rejected']);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $payment = $order->payments()->first();
        $this->assertSame(PaymentStatus::Rejected, $payment->status);
        $this->assertSame('Payment amount mismatch. Expected 25.00 EUR, received 24.90 EUR. Order has not been marked as paid.', $payment->failure_reason);

        $this->actingAs($order->buyer)->get(route('orders.result', $order))
            ->assertSee('Payment amount mismatch. Expected 25.00 EUR, received 24.90 EUR. Order has not been marked as paid.');
    }

    public function test_currency_mismatch_does_not_mark_paid(): void
    {
        $order = $this->pendingShkeeperOrder();
        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '25.00', 'EUR'))->assertJson(['status' => 'rejected']);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_partial_payment_is_recorded_and_completed_later(): void
    {
        $order = $this->pendingShkeeperOrder();

        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '10.00', extra: ['paid' => false, 'status' => 'PARTIAL']))->assertJson(['status' => 'processed']);
        $this->assertSame(PaymentStatus::Partial, $order->payments()->first()->status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        // The buyer sees what arrived and what is still due (15/25 of the 0.0004166 BTC quote).
        $this->actingAs($order->buyer);
        $this->get(route('orders.pay', $order))->assertOk()
            ->assertSee('We received 10.00 USD so far. 15.00 USD is still due.')
            ->assertSee('0.00024996 BTC');
        $this->get(route('orders.show', $order))->assertSee('(received 10.00 USD)');

        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '25.00'))->assertJson(['status' => 'processed']);
        $this->assertTrue($order->fresh()->status->isPaidState());
    }

    public function test_late_payment_for_expired_order_is_credited_to_balance(): void
    {
        $order = $this->pendingShkeeperOrder();
        $order->update(['expires_at' => now()->subMinute()]);
        app(OrderService::class)->expireStale();
        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);

        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '25.00'))->assertStatus(202);

        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);
        $this->assertSame(2500, $order->buyer->fresh()->balance_minor);
        $this->assertDatabaseHas('balance_transactions', ['user_id' => $order->buyer_id, 'type' => 'late_payment_credit', 'amount_minor' => 2500]);
    }

    public function test_unknown_order_is_acknowledged_and_ignored(): void
    {
        $this->postShkeeperWebhook($this->paidPayload('6f1b0e0c-1d2a-4b47-9a5e-2f0e2b8c9a10', '1.00'))
            ->assertStatus(202)->assertJson(['status' => 'ignored']);
        $this->assertSame(WebhookEventStatus::Ignored, WebhookEvent::first()->status);
    }

    public function test_status_poll_reconciles_missed_webhook(): void
    {
        $order = $this->pendingShkeeperOrder();
        $order->payments()->update(['created_at' => now()->subMinutes(10)]);
        Http::fake([
            'shkeeper.test/api/v1/invoices/*' => Http::response(['status' => 'success', 'invoices' => [[
                'external_id' => $order->public_id, 'status' => 'PAID', 'fiat' => 'USD', 'amount_fiat' => '25.00', 'balance_fiat' => '25.00', 'txs' => [],
            ]]]),
        ]);

        $this->artisan('shop:reconcile-payments')->assertSuccessful();
        $this->assertTrue($order->fresh()->status->isPaidState());
    }

    public function test_an_older_partial_callback_never_lowers_the_recorded_amount(): void
    {
        $order = $this->pendingShkeeperOrder();
        $partial = fn (string $amount) => $this->paidPayload($order->public_id, $amount, extra: ['paid' => false, 'status' => 'PARTIAL',
            'transactions' => [['txid' => 'tx-'.$amount, 'amount_fiat' => $amount, 'trigger' => true]]]);

        $this->postShkeeperWebhook($partial('20.00'))->assertStatus(202);
        $this->postShkeeperWebhook($partial('10.00'))->assertStatus(202);
        $this->assertSame(2000, $order->payments()->first()->received_minor);

        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '25.00'))->assertStatus(202);
        $this->postShkeeperWebhook($partial('12.50'))->assertStatus(202);
        $payment = $order->payments()->first();
        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame(2500, $payment->received_minor);
        $this->assertTrue($order->fresh()->status->isPaidState());
    }

    public function test_forged_callbacks_are_rejected_and_audited(): void
    {
        $order = $this->pendingShkeeperOrder();
        $payload = $this->paidPayload($order->public_id, '25.00');

        $this->postShkeeperWebhook($payload, secret: 'attacker-secret')->assertStatus(401);
        $this->postShkeeperWebhook($payload, timestamp: time() - 3600)->assertStatus(401);
        $this->call('POST', '/webhooks/shkeeper', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload))->assertStatus(401);

        // Audited once per address and minute, however many forged requests arrive.
        $this->assertSame(1, AuditLog::query()->where('action', 'webhook.rejected_signature')->count());
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->postShkeeperWebhook($payload, secret: 'attacker-secret')->assertStatus(401);
        $this->assertSame(2, AuditLog::query()->where('action', 'webhook.rejected_signature')->count());
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_signed_bodies_that_are_not_json_objects_get_400(): void
    {
        foreach (['[1,2,3]', '[]', '{}', '42', '"text"', 'not json', '{"external_id": "abc',
            '{"external_id": {"$gt": ""}}', '{"external_id": "x", "balance_fiat": [1]}', '{"external_id": "x", "fiat": {"a": 1}}'] as $raw) {
            $this->postShkeeperWebhook([], raw: $raw)->assertStatus(400);
        }
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_a_gateway_invoice_id_already_used_by_another_order_is_refused_cleanly(): void
    {
        $first = $this->pendingShkeeperOrder(); // the fake gateway answers invoice id 77 every time
        $product = $this->instantProductWithFile();
        $second = app(OrderService::class)->createFromCart(User::factory()->create(), [$product->id => 1], 'USD', (string) Str::uuid());

        $this->expectException(UserFacingException::class);
        try {
            app(PaymentService::class)->startShkeeperPayment($second, 'BTC');
        } finally {
            $this->assertSame(1, Payment::query()->where('provider_reference', '77')->count());
            $this->assertSame(0, $second->payments()->count());
            $this->assertSame(OrderStatus::Pending, $first->fresh()->status);
        }
    }

    public function test_oversized_bodies_are_refused_before_processing(): void
    {
        $this->postShkeeperWebhook([], raw: json_encode(['pad' => str_repeat('A', 70000)]))->assertStatus(413);
        $this->assertSame(0, WebhookEvent::query()->count());
    }
}
