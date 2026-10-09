<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookEventStatus;
use App\Models\Order;
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
}
