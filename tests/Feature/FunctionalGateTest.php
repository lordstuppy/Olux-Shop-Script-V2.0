<?php

namespace Tests\Feature;

use App\Enums\DeliveryType;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Jobs\DeliverOrder;
use App\Mail\OrderPaidMail;
use App\Mail\StaffAlertMail;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerLedgerEntry;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\RefundService;
use App\Services\Shkeeper\ShkeeperClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression tests for the defects found by the final functional gate
 * (docs/functional/test-defect-log.md, FD-01 to FD-10). Each interleaving is
 * replayed step by step, so the tests are deterministic.
 */
class FunctionalGateTest extends TestCase
{
    private function sellerWithEarnings(int $price = 10000): User
    {
        $seller = User::factory()->seller()->create();
        SellerProfile::create(['user_id' => $seller->id, 'display_name' => 'S', 'payout_currency' => 'USD', 'payout_crypto' => 'BTC',
            'payout_address' => 'bc1qsellerpayoutaddress', 'status' => 'approved']);
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => $price]);
        $buyer = User::factory()->withBalance($price)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $this->travel(15)->days();

        return $seller;
    }

    private function pendingCryptoOrder(?User $buyer = null, int $price = 2500): Order
    {
        $this->fakeShkeeper();
        $product = $this->instantProductWithFile(['price_minor' => $price]);
        $order = app(OrderService::class)->createFromCart($buyer ?? User::factory()->create(), [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->startShkeeperPayment($order, 'BTC');

        return $order;
    }

    public function test_fd01_a_rejected_payout_that_shkeeper_sent_after_all_is_booked_and_reported(): void
    {
        Mail::fake();
        config(['services.shkeeper.payouts_enabled' => true, 'services.shkeeper.payout_username' => 'payout-user',
            'services.shkeeper.payout_password' => 'payout-pass', 'services.shkeeper.payout_fees' => ['BTC' => '10']]);
        Http::fake([
            'shkeeper.test/api/v1/BTC/quote' => Http::response(['crypto_amount' => '0.00150000', 'exchange_rate' => '60000', 'status' => 'success']),
            'shkeeper.test/api/v1/BTC/payout/status*' => Http::response([]),
            'shkeeper.test/api/v1/BTC/payout' => Http::response('', 504),
        ]);
        $seller = $this->sellerWithEarnings();
        $payouts = app(PayoutService::class);
        $payout = $payouts->requestPayout($seller, 9000, 'USD');
        $this->actingAs(User::factory()->admin()->create())->confirmPassword();
        $this->postForm(route('admin.payouts.approve', $payout));
        // The transfer request gets no answer: outcome unknown, the payout stays processing.
        $this->postForm(route('admin.payouts.send', $payout))->assertSessionHas('error');
        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);

        // Shkeeper does not know it half an hour later: recorded as never sent; staff reject it.
        $this->travel(31)->minutes();
        $this->artisan('shop:reconcile-shkeeper-transfers')->assertSuccessful();
        $this->assertSame(PayoutStatus::Failed, $payout->fresh()->status);
        $this->confirmPassword()->postForm(route('admin.payouts.reject', $payout), ['note' => 'Never sent'])->assertSessionHas('success');
        $this->assertSame(9000, $payouts->balances($seller)['USD']['available']);

        // Then Shkeeper reports the transfer as sent after all.
        $raw = json_encode(['external_id' => $payout->fresh()->externalId(), 'status' => 'SUCCESS', 'tx_hash' => 'late-tx']);
        $headers = ShkeeperClient::signatureHeaders($raw, 'test-webhook-secret', time());
        $this->call('POST', '/webhooks/shkeeper/payouts', [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $raw)
            ->assertStatus(202);

        $payout->refresh();
        $this->assertSame(PayoutStatus::Paid, $payout->status);
        $this->assertSame('late-tx', $payout->reference);
        $this->assertSame(0, $payouts->balances($seller)['USD']['available'], 'the seller could withdraw money that was already sent');
        $this->assertSame(-9000, (int) SellerLedgerEntry::where('payout_id', $payout->id)->sum('amount_minor'));
        $this->assertSame([], $payouts->reconcile());
        Mail::assertQueued(StaffAlertMail::class, fn ($m) => str_contains($m->alertSubject, 'although it was rejected'));
    }

    public function test_fd02_money_on_a_refused_payment_keeps_the_order_open_and_alerts_staff_once(): void
    {
        Mail::fake();
        $order = $this->pendingCryptoOrder();
        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '24.90'))->assertJson(['status' => 'rejected']);
        Mail::assertQueued(StaffAlertMail::class, 1);

        // The deadline passes and the buyer tries to cancel: the money must not be orphaned.
        $this->travel((int) config('shop.order_ttl_minutes') + 5)->minutes();
        $this->artisan('shop:expire-orders')->assertSuccessful();
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->actingAs($order->buyer)->postForm(route('orders.cancel', $order))->assertSessionHas('error');
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        // The status poll re-reads the same refused invoice: no second audit entry or alert.
        app(PaymentService::class)->handleShkeeperNotification($this->paidPayload($order->public_id, '24.90'));
        $this->assertSame(1, AuditLog::where('action', 'payment.rejected')->count());
        Mail::assertQueued(StaffAlertMail::class, 1);

        // The buyer pays the rest: the cumulative amount pays the order.
        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '25.00'))->assertJson(['status' => 'processed']);
        $this->assertTrue($order->fresh()->status->isPaidState());
    }

    public function test_fd02_staff_can_close_an_order_after_settling_the_money_at_the_gateway(): void
    {
        Mail::fake();
        $order = $this->pendingCryptoOrder();
        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '25.00', 'EUR'))->assertJson(['status' => 'rejected']);
        $this->actingAs(User::factory()->staff(UserRole::Manager)->create())->postForm(route('admin.orders.cancel', $order))->assertSessionHas('success');
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_fd03_balance_payment_is_refused_while_crypto_has_partly_arrived(): void
    {
        $buyer = User::factory()->withBalance(10000)->create();
        $order = $this->pendingCryptoOrder($buyer);
        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '10.00', extra: ['paid' => false, 'status' => 'PARTIAL']));
        $this->assertSame(PaymentStatus::Partial, $order->payments()->first()->status);

        $this->actingAs($buyer)->postForm(route('orders.pay.balance', $order))->assertSessionHas('error');
        $this->assertSame(10000, $buyer->fresh()->balance_minor);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_fd04_saving_the_product_form_keeps_stock_that_sales_changed_meanwhile(): void
    {
        $product = Product::factory()->stock(3)->create(['price_minor' => 1000]);
        foreach (['K-1', 'K-2', 'K-3'] as $key) {
            $product->licenseKeys()->create(['key_encrypted' => $key, 'key_fingerprint' => hash('sha256', $key)]);
        }
        $seller = $product->seller;
        $form = ['title' => $product->title, 'description' => $product->description, 'price' => '10.00', 'currency' => 'USD',
            'delivery_type' => 'instant', 'stock' => '3', 'stock_seen' => '3'];

        // The seller opened the form at stock 3; a buyer takes one unit before the seller saves.
        $buyer = User::factory()->withBalance(1000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $this->assertSame(2, $product->fresh()->stock);

        $this->actingAs($seller)->putForm(route('seller.products.update', $product->id), $form)->assertSessionHas('success');
        $this->assertSame(2, $product->fresh()->stock, 'the stale stock from the form was written back (oversell)');

        // More stock than unused keys is refused outright.
        $this->putForm(route('seller.products.update', $product->id), ['stock' => '50'] + $form)->assertSessionHas('error');
        $this->putForm(route('seller.products.update', $product->id), ['stock' => ''] + $form)->assertSessionHas('error');
        $this->assertSame(2, $product->fresh()->stock);
    }

    public function test_fd05_a_resent_refund_form_refunds_once(): void
    {
        $buyer = User::factory()->withBalance(10000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$this->instantProductWithFile(['price_minor' => 10000])->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $this->actingAs(User::factory()->admin()->create())->confirmPassword();
        $form = ['_once' => (string) Str::uuid(), 'amount' => '10.00', 'method' => 'balance', 'reason' => 'Goodwill'];

        $first = $this->postForm(route('admin.orders.refund', $order), $form)->assertSessionHas('success');
        $this->postForm(route('admin.orders.refund', $order), $form)->assertSessionHas('success', $first->getSession()->get('success'));
        $this->assertSame(1000, $order->fresh()->refunded_minor);
        $this->assertSame(1000, $buyer->fresh()->balance_minor);
    }

    public function test_fd06_a_withdrawal_waits_for_a_staff_decision_in_progress(): void
    {
        Mail::fake();
        // A failed lock insert would abort the test's wrapping transaction on PostgreSQL;
        // the array store keeps the same lock semantics in one process.
        config(['cache.default' => 'array']);
        $buyer = User::factory()->withBalance(4000)->create();
        $product = $this->instantProductWithFile(['price_minor' => 4000]);
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $item = $order->items()->firstOrFail();
        $this->actingAs($buyer)->postForm(route('disputes.store', [$order, $item]), ['reason' => 'not_working', 'requested_outcome' => 'refund', 'body' => 'The download is broken.']);
        $dispute = Dispute::firstOrFail();

        // A manager is refunding it right now (holds the resolution lock).
        $lock = Cache::lock('dispute-resolve:'.$dispute->id, 60);
        $this->assertTrue($lock->get());
        $this->postForm(route('disputes.withdraw', $dispute))->assertSessionHas('error');
        $this->assertTrue($dispute->fresh()->isOpen(), 'withdrawn while staff were moving money for it');
        $lock->release();

        $this->postForm(route('disputes.withdraw', $dispute))->assertSessionHas('success');
        $this->assertSame(DisputeStatus::Withdrawn, $dispute->fresh()->status);
    }

    public function test_fd07_moderation_decisions_on_an_out_of_date_page_are_refused(): void
    {
        $product = $this->instantProductWithFile(['status' => ProductStatus::PendingReview, 'price_minor' => 1000]);
        $seen = ['seen_status' => 'pending_review', 'version' => $product->reviewVersion()];
        $this->actingAs(User::factory()->staff(UserRole::Moderator)->create());

        // The seller raises the price while the moderator looks at the old one.
        $product->forceFill(['price_minor' => 90000])->save();
        $this->postForm(route('admin.products.status', $product->id), ['status' => 'active'] + $seen)->assertSessionHas('error');
        $this->assertSame(ProductStatus::PendingReview, $product->fresh()->status);

        // Approved from a fresh page; a colleague's reject from the old page is refused.
        $fresh = ['seen_status' => 'pending_review', 'version' => $product->fresh()->reviewVersion()];
        $this->postForm(route('admin.products.status', $product->id), ['status' => 'active'] + $fresh)->assertSessionHas('success');
        $this->postForm(route('admin.products.status', $product->id), ['status' => 'disabled', 'note' => 'No.'] + $fresh)->assertSessionHas('error');
        $this->assertSame(ProductStatus::Active, $product->fresh()->status);
    }

    public function test_fd08_checkout_refuses_a_total_that_changed_after_the_page_was_shown(): void
    {
        $this->fakeShkeeper();
        $buyer = User::factory()->withBalance(5000)->create();
        $product = $this->instantProductWithFile(['price_minor' => 2000]);
        $this->actingAs($buyer)->postForm(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->get(route('checkout.show'))->assertSee('name="expected_total" value="2000"', false);

        $product->forceFill(['price_minor' => 3000])->save();
        $form = ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'balance', 'accept_terms' => '1', 'expected_total' => '2000'];
        $this->postForm(route('checkout.store'), $form)->assertSessionHas('error', 'The total changed from 20.00 USD to 30.00 USD since you opened the checkout page. Check your order and place it again.');
        $this->assertSame(0, Order::count());
        $this->assertSame(5000, $buyer->fresh()->balance_minor);

        $this->postForm(route('checkout.store'), ['expected_total' => '3000', 'idempotency_key' => (string) Str::uuid()] + $form)->assertRedirect();
        $this->assertSame(2000, $buyer->fresh()->balance_minor);
    }

    public function test_fd09_a_manual_line_can_still_be_delivered_after_another_line_was_refunded(): void
    {
        Mail::fake();
        $manual = Product::factory()->create(['delivery_type' => DeliveryType::Manual, 'price_minor' => 1000]);
        $refunded = Product::factory()->create(['delivery_type' => DeliveryType::Manual, 'price_minor' => 2000]);
        $buyer = User::factory()->withBalance(3000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$manual->id => 1, $refunded->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);

        // One line is refunded before anything was delivered.
        $refundedLine = $order->items()->where('product_id', $refunded->id)->firstOrFail();
        app(RefundService::class)->refund($order->fresh(), 2000, 'balance', User::factory()->admin()->create(), null, 'Seller cannot supply it', $refundedLine);
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->fresh()->status);

        $manualLine = $order->items()->where('product_id', $manual->id)->firstOrFail();
        $this->actingAs($manual->seller)->get(route('seller.dashboard'))->assertSee($manualLine->title);
        $this->postForm(route('seller.items.deliver', $manualLine), ['payload' => 'Your account: demo / demo'])->assertSessionHas('success');
        $this->assertNotNull($manualLine->fresh()->delivered_at);
        // The line refunded in full is not delivered, not even by staff.
        $this->actingAs(User::factory()->staff(UserRole::Manager)->create())
            ->postForm(route('admin.orders.items.deliver', [$order, $refundedLine]), ['payload' => 'x'])->assertSessionHas('error');
        $this->assertNull($refundedLine->fresh()->delivered_at);
    }

    public function test_fd10_a_second_delivery_job_does_not_email_the_buyer_again(): void
    {
        Mail::fake();
        $buyer = User::factory()->withBalance(2500)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$this->instantProductWithFile()->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        // The recovery command queued a second job while the first was still waiting.
        DeliverOrder::dispatchSync($order->id);
        Mail::assertQueued(OrderPaidMail::class, 1);
        $this->assertSame(1, UserNotification::where('user_id', $buyer->id)->where('title', 'like', '%paid%')->count());

        // Staff asking to deliver again do send it again.
        DeliverOrder::dispatchSync($order->id, resend: true);
        Mail::assertQueued(OrderPaidMail::class, 2);
    }
}
