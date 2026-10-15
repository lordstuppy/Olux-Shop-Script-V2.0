<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ShkeeperException;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\SessionController;
use App\Mail\AccountSecurityMail;
use App\Mail\StaffAlertMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\RefundService;
use App\Services\ReviewService;
use App\Services\Shkeeper\ShkeeperClient;
use App\Services\ShkeeperPayoutService;
use App\Services\TwoFactorService;
use App\Services\UserService;
use Database\Factories\UserFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Regression tests for the findings of the security audit
 * (docs/security/security-findings.md). Each test names its finding id and
 * reproduces the attack; it failed before the fix.
 */
class SecurityAuditTest extends TestCase
{
    private function pendingOrder(User $buyer, ?Product $product = null): Order
    {
        $product ??= $this->instantProductWithFile(['price_minor' => 5000]);

        return app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
    }

    private function sellerWithProfile(): User
    {
        $seller = User::factory()->seller()->create();
        SellerProfile::create(['user_id' => $seller->id, 'display_name' => 'S', 'payout_currency' => 'USD', 'payout_crypto' => 'BTC', 'payout_address' => 'bc1qsellerpayoutaddress', 'status' => 'approved']);

        return $seller;
    }

    private function otp(string $secret, int $offsetSteps = 0): string
    {
        return (new Google2FA)->oathTotp($secret, (int) floor(time() / 30) + $offsetSteps);
    }

    // SEC-01: a forged Host header must not reach URLs the shop hands out.
    public function test_sec01_host_header_does_not_reach_the_shkeeper_callback_url(): void
    {
        config(['app.url' => 'https://shop.example.test']);
        $buyer = User::factory()->create();
        $path = fn (Order $order) => route('orders.pay.start', $order, false);

        // Before the fix (URLs from the request host): Shkeeper is told to call evil.example.
        config(['shop.pin_app_url' => false]);
        $this->fakeShkeeper();
        $order = $this->pendingOrder($buyer);
        $this->actingAs($buyer)->post('http://evil.example'.$path($order), ['crypto' => 'BTC', '_token' => $this->csrfToken()]);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'payment_request') && str_starts_with((string) $r['callback_url'], 'http://evil.example/'));

        // With the fix: always APP_URL.
        config(['shop.pin_app_url' => true]);
        $this->fakeShkeeper();
        $order = $this->pendingOrder($buyer);
        $this->actingAs($buyer)->post('http://evil.example'.$path($order), ['crypto' => 'BTC', '_token' => $this->csrfToken()]);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'payment_request') && $r['callback_url'] === 'https://shop.example.test/webhooks/shkeeper');
        $this->get('http://evil.example/login')->assertRedirect('https://shop.example.test/orders');
    }

    // SEC-03: earnings a buyer can still dispute, or of an undelivered item, are not payable.
    public function test_sec03_undelivered_items_and_open_dispute_windows_hold_earnings(): void
    {
        Mail::fake();
        config(['shop.payout_hold_days' => 7, 'shop.dispute_window_days' => 14]);
        $seller = $this->sellerWithProfile();
        $product = Product::factory()->manual()->create(['seller_id' => $seller->id, 'price_minor' => 10000]);
        $buyer = User::factory()->withBalance(10000)->create();
        $order = $this->pendingOrder($buyer, $product);
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $earning = $order->items()->first()->seller_earning_minor;

        $this->travel(30)->days();
        $this->assertSame(0, app(PayoutService::class)->balances($seller)['USD']['available'], 'never delivered');
        $this->assertSame($earning, app(PayoutService::class)->balances($seller)['USD']['pending']);
        $this->assertThrows(fn () => app(PayoutService::class)->requestPayout($seller, $earning, 'USD'), UserFacingException::class);

        app(DeliveryService::class)->deliverManually($order->items()->first(), $seller, 'Access: https://example.test');
        $this->travel(13)->days();
        $this->assertSame(0, app(PayoutService::class)->balances($seller)['USD']['available'], 'dispute window open');
        $this->travel(2)->days();
        $this->assertSame($earning, app(PayoutService::class)->balances($seller)['USD']['available']);
    }

    // SEC-05: a line refunded in full no longer gives downloads, keys or review rights.
    public function test_sec05_fully_refunded_line_loses_downloads_and_review_rights(): void
    {
        Mail::fake();
        $refunded = $this->instantProductWithFile(['price_minor' => 3000]);
        $kept = $this->instantProductWithFile(['price_minor' => 2000]);
        $buyer = User::factory()->withBalance(5000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$refunded->id => 1, $kept->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $order = $order->fresh();
        $item = $order->items()->where('product_id', $refunded->id)->firstOrFail();
        $url = app(DeliveryService::class)->downloadUrl($order, $item, $refunded->files()->firstOrFail());

        app(RefundService::class)->refund($order, 3000, 'balance', User::factory()->admin()->create(), null, 'Dispute', $item);
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->fresh()->status);

        $this->actingAs($buyer)->get($url)->assertForbidden();
        $page = $this->get(route('orders.show', $order))->assertOk()->assertSee('This item was refunded')->getContent();
        $this->assertSame(1, substr_count($page, 'Download tool.zip'), 'only the line that was not refunded');
        $this->assertNull(app(ReviewService::class)->eligibleItem($buyer, $refunded));
        $this->assertNotNull(app(ReviewService::class)->eligibleItem($buyer, $kept));
    }

    // SEC-06: a late result for an earlier transfer attempt never changes the attempt in flight.
    public function test_sec06_payout_results_are_bound_to_the_attempt(): void
    {
        Mail::fake();
        config(['services.shkeeper.payouts_enabled' => true, 'services.shkeeper.payout_username' => 'u', 'services.shkeeper.payout_password' => 'p',
            'shop.staff_alert_email' => 'ops@shop.test']);
        $seller = $this->sellerWithProfile();
        $payout = Payout::create(['seller_id' => $seller->id, 'amount_minor' => 5000, 'currency' => 'USD', 'destination' => 'bc1qsellerpayoutaddress', 'status' => PayoutStatus::Approved]);
        $admin = User::factory()->admin()->create();
        $sent = [];
        Http::fake([
            'shkeeper.test/api/v1/BTC/quote*' => Http::response(['amount_crypto' => '0.001', 'status' => 'success']),
            'shkeeper.test/api/v1/BTC/payout' => function (HttpRequest $r) use (&$sent) {
                $sent[] = $r['external_id'];

                return count($sent) === 1 ? Http::response(['status' => 'error', 'msg' => 'insufficient funds'], 400) : Http::response(['task_id' => 'task-2']);
            },
        ]);
        $service = app(ShkeeperPayoutService::class);
        try {
            $service->sendPayout($payout, $admin);
        } catch (UserFacingException) {
        }
        $this->assertSame(PayoutStatus::Failed, $payout->fresh()->status);
        $service->sendPayout($payout->fresh(), $admin);
        $this->assertSame(['payout-'.$payout->id, 'payout-'.$payout->id.'-a2'], $sent);

        // The late FAIL of attempt 1 must not fail attempt 2 (which would invite a third send).
        $this->assertSame('ignored', $service->handleResult('payout-'.$payout->id, 'FAIL', null));
        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);
        // A late SUCCESS of attempt 1 alerts staff about a possible double payment.
        $service->handleResult('payout-'.$payout->id, 'SUCCESS', 'tx-old');
        Mail::assertQueued(StaffAlertMail::class);
        $this->assertSame('processed', $service->handleResult('payout-'.$payout->id.'-a2', 'SUCCESS', 'tx-new'));
        $this->assertSame(PayoutStatus::Paid, $payout->fresh()->status);
    }

    // SEC-07: the payout is claimed before money moves; a second send or a reject cannot follow.
    public function test_sec07_payout_is_processing_before_the_transfer_is_requested(): void
    {
        Mail::fake();
        config(['services.shkeeper.payouts_enabled' => true, 'services.shkeeper.payout_username' => 'u', 'services.shkeeper.payout_password' => 'p']);
        $seller = $this->sellerWithProfile();
        $payout = Payout::create(['seller_id' => $seller->id, 'amount_minor' => 5000, 'currency' => 'USD', 'destination' => 'bc1qsellerpayoutaddress', 'status' => PayoutStatus::Approved]);
        $statusDuringTransfer = null;
        Http::fake([
            'shkeeper.test/api/v1/BTC/quote*' => Http::response(['amount_crypto' => '0.001', 'status' => 'success']),
            'shkeeper.test/api/v1/BTC/payout' => function () use ($payout, &$statusDuringTransfer) {
                $statusDuringTransfer = Payout::query()->whereKey($payout->id)->value('status');

                return Http::response(['task_id' => 'task-1']);
            },
        ]);
        $admin = User::factory()->admin()->create();
        app(ShkeeperPayoutService::class)->sendPayout($payout, $admin);
        $this->assertSame(PayoutStatus::Processing, $statusDuringTransfer);

        $this->expectException(UserFacingException::class);
        app(ShkeeperPayoutService::class)->sendPayout(Payout::findOrFail($payout->id), $admin);
    }

    // SEC-08: a refund whose outcome is unknown stays pending (and refundable amount stays reserved).
    public function test_sec08_refund_timeout_is_not_treated_as_failed(): void
    {
        Mail::fake();
        config(['services.shkeeper.payouts_enabled' => true, 'services.shkeeper.payout_username' => 'u', 'services.shkeeper.payout_password' => 'p']);
        $buyer = User::factory()->withBalance(5000)->create();
        $order = $this->pendingOrder($buyer);
        app(PaymentService::class)->payWithBalance($order, $buyer);
        Http::fake([
            'shkeeper.test/api/v1/BTC/quote*' => Http::response(['amount_crypto' => '0.001', 'status' => 'success']),
            'shkeeper.test/api/v1/BTC/payout' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'),
        ]);
        try {
            app(ShkeeperPayoutService::class)->sendRefund($order->fresh(), 5000, 'BTC', 'bc1qbuyer', User::factory()->admin()->create(), 'test');
            $this->fail('expected an error');
        } catch (UserFacingException $e) {
            $this->assertStringContainsString('may already be on its way', $e->getMessage());
        }
        $refund = Payment::query()->where('order_id', $order->id)->where('kind', 'refund')->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $refund->status);
        // No second refund while the first may be in flight.
        $this->expectException(UserFacingException::class);
        app(RefundService::class)->refund($order->fresh(), 5000, 'balance', User::factory()->admin()->create());
    }

    public function test_sec08_unknown_refund_is_failed_only_when_shkeeper_never_saw_it(): void
    {
        Mail::fake();
        config(['services.shkeeper.payouts_enabled' => true, 'services.shkeeper.payout_username' => 'u', 'services.shkeeper.payout_password' => 'p']);
        $buyer = User::factory()->withBalance(5000)->create();
        $order = $this->pendingOrder($buyer);
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $refund = app(RefundService::class)->reservePending($order->fresh(), 5000, 'BTC', 'bc1qbuyer', User::factory()->admin()->create(), 'test');
        Http::fake(['shkeeper.test/api/v1/BTC/payout/status*' => Http::response(['error' => 'Payout not found'], 404)]);

        app(ShkeeperPayoutService::class)->reconcile();
        $this->assertSame(PaymentStatus::Pending, $refund->fresh()->status);
        $this->travel(31)->minutes();
        app(ShkeeperPayoutService::class)->reconcile();
        $this->assertSame(PaymentStatus::Failed, $refund->fresh()->status);
    }

    public function test_sec08_connection_refused_proves_nothing_was_sent(): void
    {
        $this->assertFalse((new ShkeeperException('x', 0, null, false))->outcomeUnknown);
        $client = new ShkeeperClient(app(Factory::class), 'https://shkeeper.test', 'k', 's', 300, 5, 'u', 'p');
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('cURL error 7: Failed to connect to shkeeper.test port 443');
            }

            return Http::response('upstream error', 502);
        });
        $outcomes = [];
        foreach ([1, 2] as $attempt) {
            try {
                $client->createPayout('BTC', '0.1', 'bc1q', '0', 'refund-1', 'https://shop.test/cb');
            } catch (ShkeeperException $e) {
                $outcomes[] = $e->outcomeUnknown;
            }
        }
        $this->assertSame([false, true], $outcomes);
    }

    // SEC-09: approve and reject act on open applications only.
    public function test_sec09_reapproving_a_seller_cannot_reset_the_commission_rate(): void
    {
        Mail::fake();
        $applicant = User::factory()->create();
        $profile = SellerProfile::create(['user_id' => $applicant->id, 'display_name' => 'Shop', 'status' => 'pending', 'payout_currency' => 'USD', 'payout_address' => 'bc1qtestaddress00']);
        app(UserService::class)->approveSeller($profile, User::factory()->admin()->create(), 2500);

        $this->actingAs(User::factory()->staff(UserRole::Manager)->create());
        $this->postForm(route('admin.sellers.approve', $profile))->assertSessionHas('error');
        $this->postForm(route('admin.sellers.reject', $profile), ['note' => 'x'])->assertSessionHas('error');
        $this->assertSame(2500, $profile->fresh()->commission_bps);
        $this->assertSame('approved', $profile->fresh()->status->value);
    }

    // SEC-10: signing out one session also ends "keep me signed in" on that device.
    public function test_sec10_single_session_sign_out_rotates_the_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-token-old-token']);
        DB::table('sessions')->insert(['id' => 'other-session-id', 'user_id' => $user->id, 'ip_address' => '10.0.0.9', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($user);
        $handle = SessionController::handle('other-session-id');
        $this->deleteForm(route('account.sessions.destroy', $handle))->assertSessionHas('success');
        $this->assertNotSame('old-token-old-token', $user->fresh()->remember_token);
    }

    // SEC-11: at most 10 two-factor codes an hour per account, counted before the check, and the owner is told.
    public function test_sec11_two_factor_codes_are_limited_per_hour_and_the_owner_is_warned(): void
    {
        Mail::fake();
        $user = User::factory()->withTwoFactor()->create();
        $pending = ['login.two_factor' => ['id' => $user->id, 'remember' => false, 'expires' => time() + 3600]];
        for ($i = 1; $i <= 10; $i++) {
            if ($i % 5 === 1 && $i > 1) {
                $this->travel(61)->seconds();
            }
            $this->withSession($pending)->postForm(route('two-factor.challenge'), ['code' => '000000'])->assertSessionHas('error');
        }
        $this->travel(61)->seconds();
        $pending['login.two_factor']['expires'] = time() + 3600;
        // The 11th attempt in the hour is refused even with the right code.
        $this->withSession($pending)->postForm(route('two-factor.challenge'), ['code' => $this->otp(UserFactory::TWO_FACTOR_SECRET)])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Too many incorrect codes'));
        $this->assertGuest();
        Mail::assertQueued(AccountSecurityMail::class, fn ($m) => $m->event === 'two_factor_locked' && $m->hasTo($user->email));
    }

    // SEC-12: an active setup cannot be replaced, and bypass codes need the second factor.
    public function test_sec12_two_factor_cannot_be_replaced_and_recovery_codes_need_a_code(): void
    {
        Mail::fake();
        $user = User::factory()->withTwoFactor()->create();
        $this->assertThrows(fn () => app(TwoFactorService::class)->enable($user, 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXQ', '123456'), UserFacingException::class);
        $this->assertSame(UserFactory::TWO_FACTOR_SECRET, $user->fresh()->two_factor_secret);

        $this->actingAs($user)->confirmPassword();
        $this->postForm(route('account.two-factor.recovery'))->assertSessionHasErrors('code');
        $this->postForm(route('account.two-factor.recovery'), ['code' => '000000'])->assertSessionHas('error');
        $this->postForm(route('account.two-factor.recovery'), ['code' => $this->otp(UserFactory::TWO_FACTOR_SECRET)])->assertSessionHas('two_factor.recovery_codes');
        Mail::assertQueued(AccountSecurityMail::class, fn ($m) => $m->event === 'recovery_codes_regenerated');
    }

    // SEC-16 / SEC-17 / SEC-18: status changes rotate remember tokens; a password change kills reset links and is announced.
    public function test_sec16_to_18_account_changes_invalidate_old_credentials_and_notify(): void
    {
        Mail::fake();
        $user = User::factory()->create(['remember_token' => 'old-token-old-token']);
        app(UserService::class)->setStatus($user, UserStatus::Suspended, User::factory()->admin()->create());
        $this->assertNotSame('old-token-old-token', $user->fresh()->remember_token);

        $user = User::factory()->create();
        Password::broker()->createToken($user);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->actingAs($user)->putForm(route('account.password'), [
            'current_password' => 'correct-horse-battery-1', 'password' => 'another-good-pass-2', 'password_confirmation' => 'another-good-pass-2',
        ])->assertSessionHas('success');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Mail::assertQueued(AccountSecurityMail::class, fn ($m) => $m->event === 'password_changed' && $m->hasTo($user->email));
    }

    // SEC-19: unlisted products do not answer with their title.
    public function test_sec19_cart_and_wishlist_do_not_reveal_unlisted_products(): void
    {
        $draft = Product::factory()->create(['title' => 'Secret upcoming thing', 'status' => ProductStatus::Draft]);
        $this->putForm(route('cart.update', $draft->id), ['quantity' => 1])->assertNotFound();
        $this->deleteForm(route('cart.remove', $draft->id))->assertNotFound();
        $this->actingAs(User::factory()->create())->deleteForm(route('wishlist.destroy', $draft->id))->assertNotFound();
    }

    // SEC-20: support staff cannot sign out a super admin.
    public function test_sec20_support_cannot_revoke_staff_sessions(): void
    {
        $admin = User::factory()->admin()->create(['remember_token' => 'admin-token-admin']);
        $this->actingAs(User::factory()->staff(UserRole::Support)->create());
        $this->postForm(route('admin.users.sessions.revoke', $admin))->assertSessionHas('error');
        $this->assertSame('admin-token-admin', $admin->fresh()->remember_token);
    }

    // SEC-21: a seller demoted to buyer is no longer listed or purchasable.
    public function test_sec21_demoted_seller_products_are_not_listed(): void
    {
        $seller = User::factory()->seller()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $this->assertTrue(Product::query()->visible()->whereKey($product->id)->exists());
        $seller->forceFill(['role' => UserRole::Buyer])->save();
        $this->assertFalse(Product::query()->visible()->whereKey($product->id)->exists());
        $this->assertFalse($product->fresh()->isPurchasable());
    }

    // SEC-22: reviewers cannot download a file the virus scanner has not passed.
    public function test_sec22_unscanned_files_are_not_downloadable_by_staff(): void
    {
        $product = $this->instantProductWithFile();
        $file = $product->files()->firstOrFail();
        $file->forceFill(['scan_status' => 'pending'])->save();
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.products.files.download', [$product->id, $file->id]))->assertStatus(409);
        $file->forceFill(['scan_status' => 'clean'])->save();
        $this->get(route('admin.products.files.download', [$product->id, $file->id]))->assertOk();
    }

    // SEC-23: nothing is delivered for a refunded order, also not by "deliver again".
    public function test_sec23_refunded_orders_are_not_redelivered(): void
    {
        Mail::fake();
        $product = Product::factory()->create(['price_minor' => 2000, 'stock' => 1]);
        $buyer = User::factory()->withBalance(2000)->create();
        $order = $this->pendingOrder($buyer, $product);
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $this->assertFalse($order->items()->first()->isDelivered(), 'no keys in stock yet');
        $admin = User::factory()->admin()->create();
        app(RefundService::class)->refund($order->fresh(), 2000, 'balance', $admin);
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);

        $product->licenseKeys()->create(['key_encrypted' => 'KEY-1', 'key_fingerprint' => hash('sha256', 'KEY-1')]);
        $this->actingAs($admin)->postForm(route('admin.orders.redeliver', $order))->assertStatus(422);
        app(DeliveryService::class)->deliver($order->fresh());
        $this->assertFalse($order->items()->first()->fresh()->isDelivered());
    }

    // SEC-24: without an exchange rate the order maximum cannot be skipped by switching currency.
    public function test_sec24_order_limits_apply_without_an_exchange_rate(): void
    {
        config(['shop.order_max_minor' => 10000, 'shop.default_currency' => 'USD']);
        $product = Product::factory()->create(['price_minor' => 50000, 'currency' => 'EUR']);
        $this->expectException(UserFacingException::class);
        app(OrderService::class)->createFromCart(User::factory()->create(), [$product->id => 1], 'EUR', (string) Str::uuid());
    }

    // SEC-25: single-line fields that end up in emails reject line breaks.
    public function test_sec25_line_breaks_are_rejected_in_single_line_fields(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postForm('/tickets', ['subject' => "Hi\n\nACTION REQUIRED: verify at https://evil.example", 'body' => 'x', 'category' => 'other'])->assertSessionHasErrors('subject');
        $this->putForm(route('account.profile'), ['name' => "Bob\r\nBcc: x"])->assertSessionHasErrors('name');
    }

    // SEC-13: seller uploads are bounded per product and per seller.
    public function test_sec13_upload_count_and_storage_quota(): void
    {
        Queue::fake();
        $seller = User::factory()->seller()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => ProductStatus::Draft]);
        $this->actingAs($seller);
        config(['shop.seller_storage_mb' => 1]);
        $this->postForm(route('seller.products.files.store', $product->id), ['file' => UploadedFile::fake()->create('big.zip', 2048)])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'storage'));
        config(['shop.seller_storage_mb' => 100, 'shop.max_product_files' => 1]);
        $this->postForm(route('seller.products.files.store', $product->id), ['file' => UploadedFile::fake()->create('a.zip', 10)])->assertSessionHas('success');
        $this->postForm(route('seller.products.files.store', $product->id), ['file' => UploadedFile::fake()->create('b.zip', 10)])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'at most 1 files'));
    }

    public function test_sec13_uploads_are_rate_limited(): void
    {
        $seller = User::factory()->seller()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => ProductStatus::Draft]);
        $this->actingAs($seller);
        for ($i = 0; $i < 10; $i++) {
            $this->postForm(route('seller.products.keys.store', $product->id), ['keys' => 'KEY-'.$i])->assertSessionHas('success');
        }
        $this->postForm(route('seller.products.keys.store', $product->id), ['keys' => 'KEY-X'])->assertStatus(429);
    }

    // SEC-31: with no trusted proxies configured, a forged Host header must not make every proxy trusted.
    public function test_sec31_forged_host_does_not_turn_on_proxy_trust(): void
    {
        config(['shop.trusted_proxies' => '']);
        Route::middleware('web')->get('/_test/ip', fn () => request()->ip().'|'.request()->getHost());
        $spoof = ['REMOTE_ADDR' => '198.51.100.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'];
        $this->call('GET', 'http://shop.on-forge.com/_test/ip', [], [], [], $spoof)->assertSee('198.51.100.4|');
        $this->call('GET', 'http://shop.on-vapor.com/_test/ip', [], [], [], $spoof)->assertSee('198.51.100.4|');

        // A trusted proxy may name the client, but never the host.
        config(['shop.trusted_proxies' => '10.0.0.0/8']);
        $this->call('GET', 'http://shop.test/_test/ip', [], [], [], ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_HOST' => 'evil.example'])
            ->assertSee('203.0.113.7|shop.test');
    }

    // SEC-34: refused requests are logged for investigation, without secret-bearing paths.
    public function test_sec34_refused_requests_are_logged(): void
    {
        Log::spy();
        $this->post('/cart/items', ['product_id' => 1])->assertStatus(419);
        $support = User::factory()->staff(UserRole::Support)->create();
        $this->actingAs($support)->get(route('admin.settings.index'))->assertForbidden();
        Log::shouldHaveReceived('notice')->withArgs(fn ($m, $c = []) => str_starts_with($m, 'Security event') && $c['status'] === 419)->once();
        Log::shouldHaveReceived('notice')->withArgs(fn ($m, $c = []) => str_starts_with($m, 'Security event') && $c['status'] === 403
            && $c['route'] === 'admin.settings.index' && $c['user_id'] === $support->id)->once();
    }

    private function processingPayout(?string $taskId): Payout
    {
        config(['services.shkeeper.payouts_enabled' => true, 'services.shkeeper.payout_username' => 'u', 'services.shkeeper.payout_password' => 'p',
            'shop.staff_alert_email' => 'ops@shop.test']);
        $seller = $this->sellerWithProfile();

        return Payout::create(['seller_id' => $seller->id, 'amount_minor' => 5000, 'currency' => 'USD', 'destination' => 'bc1qsellerpayoutaddress',
            'status' => PayoutStatus::Processing, 'provider' => 'shkeeper', 'crypto' => 'BTC', 'attempt' => 1, 'provider_reference' => $taskId, 'processed_at' => now()]);
    }

    // SEC-35 (independent review): a transfer Shkeeper accepted is never failed by the "never arrived" rule.
    public function test_sec35_accepted_transfers_are_not_failed_by_the_timeout_rule(): void
    {
        Mail::fake();
        $payout = $this->processingPayout('task-9');
        Http::fake(['shkeeper.test/api/v1/BTC/payout/status*' => Http::response(['error' => 'Payout not found'], 404)]);
        $this->travel(2)->hours();
        app(ShkeeperPayoutService::class)->reconcile();
        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);
    }

    public function test_sec35_a_proxy_404_does_not_prove_a_transfer_was_never_sent(): void
    {
        Mail::fake();
        $unsent = $this->processingPayout(null);
        Http::fake(['shkeeper.test/api/v1/BTC/payout/status*' => Http::response('<html>Not Found</html>', 404)]);
        $this->travel(1)->hours();
        app(ShkeeperPayoutService::class)->reconcile();
        $this->assertSame(PayoutStatus::Processing, $unsent->fresh()->status);
    }

    public function test_sec35_success_after_an_assumed_failure_is_not_lost(): void
    {
        Mail::fake();
        $payout = $this->processingPayout(null);
        $payout->forceFill(['status' => PayoutStatus::Failed, 'failure_reason' => 'never sent'])->save();
        $this->assertSame('processed', app(ShkeeperPayoutService::class)->handleResult('payout-'.$payout->id, 'SUCCESS', 'tx-late'));
        $this->assertSame(PayoutStatus::Paid, $payout->fresh()->status);
        Mail::assertQueued(StaffAlertMail::class);
    }

    public function test_sec35_reply_without_task_id_leaves_the_payout_processing(): void
    {
        Mail::fake();
        config(['services.shkeeper.payouts_enabled' => true, 'services.shkeeper.payout_username' => 'u', 'services.shkeeper.payout_password' => 'p']);
        $seller = $this->sellerWithProfile();
        $payout = Payout::create(['seller_id' => $seller->id, 'amount_minor' => 5000, 'currency' => 'USD', 'destination' => 'bc1qsellerpayoutaddress', 'status' => PayoutStatus::Approved]);
        Http::fake([
            'shkeeper.test/api/v1/BTC/quote*' => Http::response(['amount_crypto' => '0.001', 'status' => 'success']),
            'shkeeper.test/api/v1/BTC/payout' => Http::response(['status' => 'success']),
        ]);
        $this->assertThrows(fn () => app(ShkeeperPayoutService::class)->sendPayout($payout, User::factory()->admin()->create()), UserFacingException::class);
        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);
    }

    // SEC-36 (independent review): the per-account code budget also covers turning 2FA off and new recovery codes.
    public function test_sec36_two_factor_budget_covers_account_settings(): void
    {
        Mail::fake();
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user)->confirmPassword();
        for ($i = 1; $i <= 10; $i++) {
            if ($i === 6) {
                $this->travel(61)->seconds();
            }
            $this->postForm(route('account.two-factor.recovery'), ['code' => '000000'])->assertSessionHas('error');
        }
        $this->travel(61)->seconds();
        $this->confirmPassword();
        $this->postForm(route('account.two-factor.recovery'), ['code' => $this->otp(UserFactory::TWO_FACTOR_SECRET)])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Too many incorrect codes'));
        Mail::assertQueued(AccountSecurityMail::class, fn ($m) => $m->event === 'two_factor_locked');
    }

    public function test_sec36_enabling_two_factor_is_not_a_password_oracle(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/account/two-factor');
        for ($i = 0; $i < 5; $i++) {
            $this->postForm('/account/two-factor', ['code' => '123456', 'current_password' => 'guess-'.$i])->assertSessionHasErrors('current_password');
        }
        $this->postForm('/account/two-factor', ['code' => '123456', 'current_password' => 'guess-x'])->assertStatus(429);
    }

    // SEC-37 (independent review): staff never refund, deliver, pay or credit themselves.
    public function test_sec37_staff_cannot_act_on_their_own_orders_payouts_or_balance(): void
    {
        Mail::fake();
        $finance = User::factory()->staff(UserRole::Finance)->withBalance(5000)->create();
        $order = $this->pendingOrder($finance);
        app(PaymentService::class)->payWithBalance($order, $finance);
        $this->actingAs($finance)->confirmPassword();
        $this->postForm(route('admin.orders.refund', $order), ['_once' => (string) Str::uuid(), 'amount' => '50.00', 'method' => 'balance'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Another team member'));
        $this->assertSame(0, $order->fresh()->refunded_minor);

        $this->postForm(route('admin.users.balance', $finance), ['_once' => (string) Str::uuid(), 'direction' => 'credit', 'amount' => '100.00', 'currency' => 'USD', 'reason' => 'x'])
            ->assertSessionHas('error');
        $this->assertSame(0, $finance->fresh()->balance_minor);

        $payout = Payout::create(['seller_id' => $finance->id, 'amount_minor' => 5000, 'currency' => 'USD', 'destination' => 'bc1q', 'status' => PayoutStatus::Requested]);
        $this->postForm(route('admin.payouts.approve', $payout))->assertSessionHas('error');
        $this->assertSame(PayoutStatus::Requested, $payout->fresh()->status);
    }

    // SEC-38 (independent review): the refund check is repeated under the item row lock.
    public function test_sec38_a_line_refunded_before_delivery_gets_nothing(): void
    {
        Mail::fake();
        $product = Product::factory()->create(['price_minor' => 2000, 'stock' => 1]);
        $buyer = User::factory()->withBalance(2000)->create();
        $order = $this->pendingOrder($buyer, $product);
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $item = $order->items()->firstOrFail();
        $this->assertFalse($item->isDelivered());
        // The order is still "paid" while its only line is already refunded in full.
        $item->forceFill(['refunded_minor' => $item->netMinor()])->save();
        $product->licenseKeys()->create(['key_encrypted' => 'KEY-1', 'key_fingerprint' => hash('sha256', 'KEY-1')]);
        app(DeliveryService::class)->deliver($order->fresh());
        $this->assertFalse($item->fresh()->isDelivered());
        $this->assertSame(1, $product->licenseKeys()->whereNull('order_item_id')->count());
    }

    // SEC-27: Shkeeper credentials never follow a redirect to another host.
    public function test_sec27_shkeeper_client_does_not_follow_redirects(): void
    {
        Http::fake([
            'shkeeper.test/*' => Http::response('', 302, ['Location' => 'https://evil.example/steal']),
            'evil.example/*' => Http::response(['task_id' => 'stolen']),
        ]);
        $client = new ShkeeperClient(app(Factory::class), 'https://shkeeper.test', 'k', 's', 300, 5, 'u', 'p');
        try {
            $client->createPayout('BTC', '0.1', 'bc1q', '0', 'payout-1', 'https://shop.test/cb');
            $this->fail('a redirect must not be followed');
        } catch (ShkeeperException) {
        }
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil.example'));
    }
}
