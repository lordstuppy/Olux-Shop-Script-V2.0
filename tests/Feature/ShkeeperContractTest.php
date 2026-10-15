<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Exceptions\ShkeeperException;
use App\Mail\StaffAlertMail;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\RefundService;
use App\Services\Shkeeper\ShkeeperClient;
use App\Services\ShkeeperPayoutService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression tests for the mismatches the contract test against a real
 * Shkeeper found (docs/functional/shkeeper-contract-report.md, SK-1 to SK-3).
 * The fakes use the answers the public demo and the Shkeeper source give.
 */
class ShkeeperContractTest extends TestCase
{
    /** Quote answer as the demo sent it (keys and types as received). */
    private const QUOTE = ['amount_crypto' => '0.10865', 'amount_fiat' => '12.34', 'crypto' => 'BTC', 'exchange_rate' => '113.57', 'fiat' => 'USD', 'status' => 'success'];

    /** Payout status answer for an external id Shkeeper does not know. */
    private const PAYOUT_NOT_FOUND = ['error' => 'Payout not found'];

    private function client(): ShkeeperClient
    {
        return new ShkeeperClient(app(Factory::class), 'https://shkeeper.test', 'k', 's', 300, 5, 'u', 'p');
    }

    private function enablePayouts(): void
    {
        config(['services.shkeeper.payouts_enabled' => true, 'services.shkeeper.payout_username' => 'payout-user',
            'services.shkeeper.payout_password' => 'payout-pass', 'services.shkeeper.payout_fees' => ['BTC' => '10']]);
    }

    private function pendingCryptoOrder(): Order
    {
        $this->fakeShkeeper();
        $product = $this->instantProductWithFile(['price_minor' => 2500]);
        $order = app(OrderService::class)->createFromCart(User::factory()->create(), [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->startShkeeperPayment($order, 'BTC');

        return $order;
    }

    public function test_sk1_quote_reads_the_field_shkeeper_sends(): void
    {
        Http::fake(['shkeeper.test/api/v1/BTC/quote' => Http::sequence()
            ->push(self::QUOTE)
            ->push(['crypto_amount' => '0.5', 'status' => 'success'])
            ->push(['status' => 'success', 'fiat' => 'USD'])]);

        $this->assertSame('0.10865', $this->client()->quote('BTC', 1234, 'USD'));
        // The field name Shkeeper's README documents is still read.
        $this->assertSame('0.5', $this->client()->quote('BTC', 1234, 'USD'));
        $this->expectException(ShkeeperException::class);
        $this->client()->quote('BTC', 1234, 'USD');
    }

    public function test_sk1_a_payout_through_shkeeper_is_sent(): void
    {
        Mail::fake();
        $this->enablePayouts();
        Http::fake([
            'shkeeper.test/api/v1/BTC/quote' => Http::response(self::QUOTE),
            'shkeeper.test/api/v1/BTC/payout' => Http::response(['task_id' => 'task-1']),
        ]);
        $seller = User::factory()->seller()->create();
        SellerProfile::create(['user_id' => $seller->id, 'display_name' => 'S', 'payout_currency' => 'USD', 'payout_crypto' => 'BTC',
            'payout_address' => 'bc1qsellerpayoutaddress', 'status' => 'approved']);
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 10000]);
        $buyer = User::factory()->withBalance(10000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $this->travel(15)->days();

        $payout = app(PayoutService::class)->requestPayout($seller, 9000, 'USD');
        $this->actingAs(User::factory()->admin()->create())->confirmPassword();
        $this->postForm(route('admin.payouts.approve', $payout));
        $this->postForm(route('admin.payouts.send', $payout))->assertSessionHas('success');

        $payout->refresh();
        $this->assertSame(PayoutStatus::Processing, $payout->status);
        $this->assertSame('0.10865', $payout->crypto_amount);
        $this->assertSame('task-1', $payout->provider_reference);
    }

    public function test_sk2_an_unconfirmed_transaction_callback_neither_rejects_the_payment_nor_alerts_staff(): void
    {
        Mail::fake();
        $order = $this->pendingCryptoOrder();
        // Sent by Shkeeper when the transaction first appears, before any confirmation.
        $unconfirmed = ['status' => 'unconfirmed', 'external_id' => $order->public_id, 'crypto' => 'BTC', 'addr' => 'bc1qtestaddress',
            'txid' => 'tx-1', 'amount' => '0.00041'];

        $this->postShkeeperWebhook($unconfirmed)->assertStatus(202)->assertJson(['status' => 'ignored']);

        $payment = $order->payments()->first();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNull($payment->failure_reason);
        $this->assertSame(0, AuditLog::where('action', 'payment.rejected')->count());
        Mail::assertNotQueued(StaffAlertMail::class);

        // The confirmed callback that follows pays the order.
        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '25.00'))->assertJson(['status' => 'processed']);
        $this->assertTrue($order->fresh()->status->isPaidState());
        Mail::assertNotQueued(StaffAlertMail::class);
    }

    public function test_sk3_shkeepers_payout_not_found_answer_reads_as_never_arrived(): void
    {
        Http::fake(['shkeeper.test/api/v1/BTC/payout/status*' => Http::sequence()
            ->push(self::PAYOUT_NOT_FOUND, 404)
            ->push(['status' => 'error', 'message' => 'payout not found'], 404)
            ->push('<html>Not Found</html>', 404)
            ->push(['error' => ['nested']], 404)]);

        $this->assertNull($this->client()->payoutStatus('BTC', 'payout-1'));
        $this->assertNull($this->client()->payoutStatus('BTC', 'payout-1'));
        // A 404 that is not Shkeeper's own answer proves nothing.
        foreach ([1, 2] as $ignored) {
            try {
                $this->client()->payoutStatus('BTC', 'payout-1');
                $this->fail('A foreign 404 must not read as "not found".');
            } catch (ShkeeperException $e) {
                $this->assertFalse($e->shkeeperNotFound);
            }
        }
    }

    public function test_sk3_a_refund_shkeeper_never_received_is_failed_after_30_minutes(): void
    {
        Mail::fake();
        $this->enablePayouts();
        $buyer = User::factory()->withBalance(5000)->create();
        $product = $this->instantProductWithFile(['price_minor' => 5000]);
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $refund = app(RefundService::class)->reservePending($order->fresh(), 5000, 'BTC', 'bc1qbuyer', User::factory()->admin()->create(), 'test');
        Http::fake(['shkeeper.test/api/v1/BTC/payout/status*' => Http::response(self::PAYOUT_NOT_FOUND, 404)]);

        app(ShkeeperPayoutService::class)->reconcile();
        $this->assertSame(PaymentStatus::Pending, $refund->fresh()->status);
        $this->travel(31)->minutes();
        app(ShkeeperPayoutService::class)->reconcile();
        $this->assertSame(PaymentStatus::Failed, $refund->fresh()->status);
    }
}
