<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Mail\PayoutAddressChangeMail;
use App\Mail\PayoutStatusMail;
use App\Mail\RefundIssuedMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\Shkeeper\ShkeeperClient;
use App\Services\ShkeeperPayoutService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentAutomationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.shkeeper.payouts_enabled' => true,
            'services.shkeeper.payout_username' => 'payout-user',
            'services.shkeeper.payout_password' => 'payout-pass',
            'services.shkeeper.payout_fees' => ['BTC' => '10'],
        ]);
    }

    private function fakeTransfers(string $taskId = 'task-1'): void
    {
        Http::fake([
            'shkeeper.test/api/v1/crypto' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/shkeeper/crypto.json')), true)),
            'shkeeper.test/api/v1/*/payment_request' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/shkeeper/payment_request.json')), true)),
            'shkeeper.test/api/v1/BTC/quote' => Http::response(['crypto_amount' => '0.00150000', 'exchange_rate' => '60000', 'status' => 'success']),
            'shkeeper.test/api/v1/BTC/payout' => Http::response(['task_id' => $taskId, 'external_id' => 'x']),
        ]);
    }

    private function postPayoutCallback(array $payload): TestResponse
    {
        $raw = json_encode($payload);
        $headers = ShkeeperClient::signatureHeaders($raw, 'test-webhook-secret', time());

        return $this->call('POST', '/webhooks/shkeeper/payouts', [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $raw);
    }

    private function sellerWithEarnings(int $price = 10000): User
    {
        $seller = User::factory()->seller()->create();
        SellerProfile::create(['user_id' => $seller->id, 'display_name' => 'S', 'payout_currency' => 'USD', 'payout_crypto' => 'BTC', 'payout_address' => 'bc1qsellerpayoutaddress', 'status' => 'approved']);
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => $price]);
        $buyer = User::factory()->withBalance($price)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $this->travel(8)->days();

        return $seller;
    }

    public function test_stale_quote_must_be_refreshed(): void
    {
        $this->fakeShkeeper();
        $buyer = User::factory()->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$this->instantProductWithFile()->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->startShkeeperPayment($order, 'BTC');
        $this->actingAs($buyer);

        $this->get(route('orders.pay', $order))->assertSee('This amount is valid until')->assertSee('bc1qfixture');
        $this->travel(16)->minutes();
        $this->get(route('orders.pay', $order))->assertSee('Refresh the amount before paying')->assertDontSee('bc1qfixturewalletaddress');

        $this->postForm(route('orders.pay.start', $order), ['crypto' => 'BTC'])->assertRedirect(route('orders.pay', $order));
        $this->get(route('orders.pay', $order))->assertSee('bc1qfixturewalletaddress');
    }

    public function test_overpayment_is_credited_to_balance(): void
    {
        $this->fakeShkeeper();
        $buyer = User::factory()->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$this->instantProductWithFile(['price_minor' => 2500])->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->startShkeeperPayment($order, 'BTC');

        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '27.40', extra: ['status' => 'OVERPAID']))->assertJson(['status' => 'processed']);
        $this->assertTrue($order->fresh()->status->isPaidState());
        $this->assertSame(240, $buyer->fresh()->balance_minor);
        $this->assertDatabaseHas('balance_transactions', ['user_id' => $buyer->id, 'type' => 'overpayment_credit', 'amount_minor' => 240]);

        // Replay does not credit twice.
        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '27.40', extra: ['status' => 'OVERPAID', 'transactions' => [['txid' => 'b']]]));
        $this->assertSame(240, $buyer->fresh()->balance_minor);
    }

    public function test_payout_sent_via_shkeeper_and_confirmed_by_callback(): void
    {
        Mail::fake();
        $this->fakeTransfers('task-77');
        $seller = $this->sellerWithEarnings();
        $payout = app(PayoutService::class)->requestPayout($seller, 9000, 'USD');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->confirmPassword();
        // Must be approved first.
        $this->postForm(route('admin.payouts.send', $payout))->assertSessionHas('error');
        $this->postForm(route('admin.payouts.approve', $payout));
        $this->postForm(route('admin.payouts.send', $payout))->assertSessionHas('success');

        $payout->refresh();
        $this->assertSame(PayoutStatus::Processing, $payout->status);
        $this->assertSame('0.00150000', $payout->crypto_amount);
        $this->assertSame('task-77', $payout->provider_reference);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/BTC/payout') && $r['destination'] === 'bc1qsellerpayoutaddress'
            && $r['external_id'] === 'payout-'.$payout->id && $r['fee'] === '10' && $r['amount'] === '0.00150000');

        // Unsigned callbacks are refused.
        $this->postJson('/webhooks/shkeeper/payouts', ['external_id' => 'payout-'.$payout->id, 'status' => 'SUCCESS'])->assertStatus(401);

        $this->postPayoutCallback(['external_id' => 'payout-'.$payout->id, 'status' => 'SUCCESS', 'tx_hash' => 'chain-tx-1'])->assertStatus(202)->assertJson(['status' => 'processed']);
        $this->postPayoutCallback(['external_id' => 'payout-'.$payout->id, 'status' => 'SUCCESS', 'tx_hash' => 'chain-tx-1', 'n' => 2])->assertJson(['status' => 'ignored']);

        $payout->refresh();
        $this->assertSame(PayoutStatus::Paid, $payout->status);
        $this->assertSame('chain-tx-1', $payout->reference);
        Mail::assertQueued(PayoutStatusMail::class, 1);
        $this->assertSame([], app(PayoutService::class)->reconcile());
    }

    public function test_failed_payout_can_be_resent_or_rejected(): void
    {
        $this->fakeTransfers();
        $seller = $this->sellerWithEarnings();
        $payout = app(PayoutService::class)->requestPayout($seller, 9000, 'USD');
        $this->actingAs(User::factory()->admin()->create())->confirmPassword();
        $this->postForm(route('admin.payouts.approve', $payout));
        $this->postForm(route('admin.payouts.send', $payout));

        $this->postPayoutCallback(['external_id' => 'payout-'.$payout->id, 'status' => 'FAIL']);
        $this->assertSame(PayoutStatus::Failed, $payout->fresh()->status);
        // Money stays reserved while failed; rejecting returns it.
        $this->assertSame(0, app(PayoutService::class)->balances($seller)['USD']['available']);
        $this->postForm(route('admin.payouts.reject', $payout), ['note' => 'Invalid address'])->assertSessionHas('success');
        $this->assertSame(9000, app(PayoutService::class)->balances($seller)['USD']['available']);
    }

    public function test_status_poll_completes_processing_payouts(): void
    {
        $this->fakeTransfers();
        $seller = $this->sellerWithEarnings();
        $payout = app(PayoutService::class)->requestPayout($seller, 9000, 'USD');
        $admin = User::factory()->admin()->create();
        app(PayoutService::class)->approve($payout, $admin);
        app(ShkeeperPayoutService::class)->sendPayout($payout->fresh(), $admin);

        Http::fake(['shkeeper.test/api/v1/BTC/payout/status*' => Http::response(['external_id' => 'payout-'.$payout->id, 'status' => 'SUCCESS', 'txid' => 'polled-tx'])]);
        $this->artisan('shop:reconcile-shkeeper-transfers')->assertSuccessful();
        $this->assertSame('polled-tx', $payout->fresh()->reference);
    }

    public function test_crypto_refund_is_booked_only_after_confirmation(): void
    {
        Mail::fake();
        $this->fakeTransfers('task-r');
        $seller = $this->sellerWithEarnings(5000);
        $order = Order::firstOrFail();

        $this->actingAs(User::factory()->admin()->create())->confirmPassword();
        $this->postForm(route('admin.orders.refund', $order), ['amount' => '30.00', 'method' => 'shkeeper', 'crypto' => 'BTC', 'destination' => 'bc1qbuyerrefund'])
            ->assertSessionHas('success');

        $refund = Payment::where('kind', PaymentKind::Refund->value)->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $refund->status);
        $this->assertSame(0, $order->fresh()->refunded_minor);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        // The pending refund counts against what is refundable.
        $this->postForm(route('admin.orders.refund', $order), ['amount' => '30.00', 'method' => 'balance'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'exceeds the refundable 20.00 USD') && str_contains($m, '30.00 USD is already being refunded'));

        $this->postPayoutCallback(['external_id' => 'refund-'.$refund->id, 'status' => 'SUCCESS', 'tx_hash' => 'refund-tx']);
        $refund->refresh();
        $this->assertSame(PaymentStatus::Confirmed, $refund->status);
        $this->assertSame('refund-tx', $refund->provider_reference);
        $this->assertSame(3000, $order->fresh()->refunded_minor);
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->fresh()->status);
        Mail::assertQueued(RefundIssuedMail::class, 1);
        $this->assertSame([], app(PayoutService::class)->reconcile());
    }

    public function test_failed_crypto_refund_changes_nothing(): void
    {
        $this->fakeTransfers();
        $this->sellerWithEarnings(5000);
        $order = Order::firstOrFail();
        $refund = app(ShkeeperPayoutService::class)->sendRefund($order, 5000, 'BTC', 'bc1qbuyer', User::factory()->admin()->create(), null);

        $this->postPayoutCallback(['external_id' => 'refund-'.$refund->id, 'status' => 'FAIL']);
        $this->assertSame(PaymentStatus::Failed, $refund->fresh()->status);
        $this->assertSame(0, $order->fresh()->refunded_minor);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_payouts_disabled_means_manual_only(): void
    {
        config(['services.shkeeper.payouts_enabled' => false]);
        $seller = $this->sellerWithEarnings();
        $payout = app(PayoutService::class)->requestPayout($seller, 9000, 'USD');
        $this->actingAs(User::factory()->admin()->create())->confirmPassword();
        $this->postForm(route('admin.payouts.approve', $payout));
        $this->postForm(route('admin.payouts.send', $payout))->assertSessionHas('error', fn ($m) => str_contains($m, 'Automatic payouts are turned off'));
        $this->get(route('admin.payouts.index'))->assertDontSee('via Shkeeper</button>', false);
    }

    public function test_payout_address_change_needs_email_confirmation_and_cooldown(): void
    {
        Mail::fake();
        $this->fakeTransfers();
        $seller = $this->sellerWithEarnings();
        $this->actingAs($seller);

        // Password confirmation first.
        $this->get(route('seller.payout-settings'))->assertOk();
        $this->postForm(route('seller.payout-settings.store'), ['payout_crypto' => 'BTC', 'payout_address' => 'bc1qattackeraddress'])->assertRedirect(route('password.confirm'));
        $this->confirmPassword()->postForm(route('seller.payout-settings.store'), ['payout_crypto' => 'BTC', 'payout_address' => 'bc1qnewsellerwallet'])->assertSessionHas('success');
        $this->assertSame('bc1qsellerpayoutaddress', $seller->sellerProfile->fresh()->payout_address);

        $url = null;
        Mail::assertQueued(PayoutAddressChangeMail::class, function ($mail) use (&$url) {
            $url = $mail->confirmUrl;

            return true;
        });
        $this->get(route('seller.payout-address.confirm', 'wrong-token'))->assertStatus(422);
        $this->get($url)->assertOk()->assertSee('bc1qnewsellerwallet');
        $this->postForm($url)->assertRedirect(route('seller.payout-settings'));
        $profile = $seller->sellerProfile->fresh();
        $this->assertSame('bc1qnewsellerwallet', $profile->payout_address);

        // Cool-down blocks payouts for 48 hours.
        $this->postForm(route('seller.payouts.store'), ['amount' => '50.00', 'currency' => 'USD'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'payouts are possible again after'));
        $this->travel(49)->hours();
        $this->postForm(route('seller.payouts.store'), ['amount' => '50.00', 'currency' => 'USD'])->assertSessionHas('success');
        $this->assertSame('bc1qnewsellerwallet', Payout::firstOrFail()->destination);
        // The link works only once.
        $this->get($url)->assertStatus(422);
    }
}
