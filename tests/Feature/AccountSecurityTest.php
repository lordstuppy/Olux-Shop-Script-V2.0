<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\SessionController;
use App\Mail\NewDeviceLoginMail;
use App\Models\Product;
use App\Models\User;
use App\Notifications\VerifyEmailQueued;
use App\Services\GiftCardService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TwoFactorService;
use App\Services\UserService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    private function otp(string $secret, int $offsetSteps = 0): string
    {
        $g = new Google2FA;

        return $g->oathTotp($secret, (int) floor(time() / 30) + $offsetSteps);
    }

    public function test_trusted_proxies_come_from_config(): void
    {
        Route::middleware('web')->get('/_test/ip', fn () => request()->ip());
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'];

        $this->call('GET', '/_test/ip', [], [], [], $server)->assertSee('10.0.0.5');
        config(['shop.trusted_proxies' => '10.0.0.0/8']);
        $this->call('GET', '/_test/ip', [], [], [], $server)->assertSee('203.0.113.7');
    }

    public function test_open_unpaid_orders_are_capped(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create();
        $orders = app(OrderService::class);
        for ($i = 0; $i < 3; $i++) {
            $orders->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        }

        $this->expectExceptionMessage('You have 3 unpaid orders. Pay or cancel one in Your orders before placing a new one.');
        $orders->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
    }

    public function test_unverified_users_cannot_check_out_until_they_confirm(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $this->get('/checkout')->assertRedirect(route('verification.notice'));
        $this->postForm('/wallet/redeem', ['code' => 'X'])->assertRedirect(route('verification.notice'));

        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($link)->assertRedirect(route('products.index'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $tampered = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('other@example.test')]);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get(str_replace('signature=', 'signature=x', $tampered))->assertForbidden();
    }

    public function test_registration_sends_verification_and_rejects_bots(): void
    {
        $data = ['name' => 'Bob', 'email' => 'bob@example.test', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123', 'accept_terms' => '1'];

        $this->postForm('/register', $data + ['form_token' => $this->formToken(), 'website' => 'http://spam'])->assertSessionHas('error');
        $fast = Crypt::encryptString((string) time());
        $this->postForm('/register', $data + ['form_token' => $fast])->assertSessionHas('error');
        $this->postForm('/register', $data)->assertSessionHas('error');
        $this->assertSame(0, User::count());

        Notification::fake();
        $this->postForm('/register', $data + ['form_token' => $this->formToken()])->assertRedirect(route('verification.notice'));
        $user = User::firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmailQueued::class);
    }

    public function test_enable_two_factor_and_sign_in_with_it(): void
    {
        $user = User::factory()->create(['email' => 'tfa@example.test']);
        $this->actingAs($user);

        $this->get('/account/two-factor')->assertOk()->assertSee('data:image/svg+xml;base64', false);
        $secret = session('two_factor.setup_secret');
        $this->postForm('/account/two-factor', ['code' => '000000'])->assertSessionHas('error');
        $this->postForm('/account/two-factor', ['code' => $this->otp($secret)])->assertSessionHas('two_factor.recovery_codes');
        $codes = session('two_factor.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactor());

        // Sign in: password alone is not enough.
        $this->postForm('/logout');
        $this->postForm('/login', ['email' => 'tfa@example.test', 'password' => 'correct-horse-battery-1'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->postForm('/two-factor-challenge', ['code' => '123456'])->assertSessionHas('error');
        $this->assertGuest();

        // The code used at setup cannot be replayed; the next time step works.
        $this->postForm('/two-factor-challenge', ['code' => $this->otp($secret)])->assertSessionHas('error');
        $this->postForm('/two-factor-challenge', ['code' => $this->otp($secret, 1)])->assertRedirect(route('account.orders'));
        $this->assertAuthenticatedAs($user);

        // A recovery code works exactly once.
        $this->postForm('/logout');
        $this->postForm('/login', ['email' => 'tfa@example.test', 'password' => 'correct-horse-battery-1']);
        $this->postForm('/two-factor-challenge', ['code' => strtolower($codes[0])])->assertRedirect(route('account.orders'));
        $this->postForm('/logout');
        $this->postForm('/login', ['email' => 'tfa@example.test', 'password' => 'correct-horse-battery-1']);
        $this->postForm('/two-factor-challenge', ['code' => $codes[0]])->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_two_factor_challenge_is_rate_limited(): void
    {
        $user = User::factory()->withTwoFactor()->create(['email' => 'rl@example.test']);
        $this->postForm('/login', ['email' => 'rl@example.test', 'password' => 'correct-horse-battery-1']);
        for ($i = 0; $i < 5; $i++) {
            $this->postForm('/two-factor-challenge', ['code' => '000000']);
        }
        $this->postForm('/two-factor-challenge', ['code' => $this->otp(UserFactory::TWO_FACTOR_SECRET)])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Too many incorrect codes'));
        $this->assertGuest();
    }

    public function test_staff_need_two_factor_for_admin_area(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/admin')->assertRedirect(route('account.two-factor'));

        $admin->forceFill(['two_factor_secret' => UserFactory::TWO_FACTOR_SECRET, 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin->fresh())->get('/admin')->assertOk();
    }

    public function test_staff_roles_are_limited_by_permission(): void
    {
        $support = User::factory()->staff(UserRole::Support)->create();
        $this->actingAs($support);
        $this->get('/admin')->assertOk();
        $this->get('/admin/tickets')->assertOk();
        $this->get('/admin/orders')->assertOk();
        $this->get('/admin/payouts')->assertForbidden();
        $this->get('/admin/audit')->assertForbidden();
        $this->get('/admin')->assertDontSee('Payouts');

        $finance = User::factory()->staff(UserRole::Finance)->create();
        $this->actingAs($finance);
        $this->get('/admin/payouts')->assertOk();
        $this->get('/admin/payments')->assertOk();
        $this->get('/admin/tickets')->assertForbidden();
        $this->get('/admin/products')->assertForbidden();
        $this->confirmPassword()->postForm(route('admin.users.role', User::factory()->create()), ['role' => 'admin'])->assertForbidden();
    }

    public function test_sensitive_actions_require_recent_password(): void
    {
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 1000]);
        $buyer = User::factory()->withBalance(1000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->get(route('admin.orders.show', $order));
        $this->postForm(route('admin.orders.refund', $order), ['amount' => '1.00', 'method' => 'balance'])
            ->assertRedirect(route('password.confirm'));
        $this->assertSame(0, $order->fresh()->refunded_minor);

        $this->postForm(route('password.confirm'), ['password' => 'wrong'])->assertSessionHas('error');
        $this->postForm(route('password.confirm'), ['password' => 'correct-horse-battery-1'])->assertRedirect(route('admin.orders.show', $order));
        $this->postForm(route('admin.orders.refund', $order), ['amount' => '1.00', 'method' => 'balance'])->assertSessionHas('success');
        $this->assertSame(100, $order->fresh()->refunded_minor);

        $this->travel(16)->minutes();
        $this->postForm(route('admin.orders.refund', $order), ['amount' => '1.00', 'method' => 'balance'])->assertRedirect(route('password.confirm'));
    }

    public function test_new_device_login_sends_alert(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'dev@example.test']);
        $login = fn () => $this->postForm('/login', ['email' => 'dev@example.test', 'password' => 'correct-horse-battery-1']);

        $login()->assertRedirect();
        Mail::assertNothingQueued();
        $cookie = collect($this->app['cookie']->getQueuedCookies())->first(fn ($c) => $c->getName() === UserService::DEVICE_COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());

        // Same device again: no alert.
        $this->postForm('/logout');
        $this->withCookie(UserService::DEVICE_COOKIE, $cookie->getValue());
        $login();
        Mail::assertNothingQueued();

        // Another device: alert.
        $this->postForm('/logout');
        $this->withCookie(UserService::DEVICE_COOKIE, str_repeat('a', 64));
        $login();
        Mail::assertQueued(NewDeviceLoginMail::class, fn ($m) => $m->hasTo('dev@example.test'));
        $this->assertSame(2, DB::table('user_devices')->where('user_id', $user->id)->count());
    }

    public function test_user_can_sign_out_other_sessions_and_staff_can_revoke(): void
    {
        $user = User::factory()->create();
        foreach (['s1', 's2'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '198.51.100.1', 'user_agent' => 'X', 'payload' => '', 'last_activity' => time()]);
        }
        $this->actingAs($user);
        $this->deleteForm(route('account.sessions.destroy', SessionController::handle('s1')))->assertSessionHas('success');
        $this->assertSame(['s2'], DB::table('sessions')->pluck('id')->all());

        $this->actingAs(User::factory()->staff(UserRole::Support)->create());
        $this->postForm(route('admin.users.sessions.revoke', $user))->assertSessionHas('success');
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_webhook_source_allowlist(): void
    {
        config(['shop.webhook_allowed_ips' => '10.0.0.0/8']);
        $this->postShkeeperWebhook($this->paidPayload((string) Str::uuid(), '1.00'))->assertStatus(403);

        config(['shop.webhook_allowed_ips' => '127.0.0.1']);
        $this->postShkeeperWebhook($this->paidPayload((string) Str::uuid(), '1.00'))->assertStatus(202);
    }

    public function test_gift_cards_and_recovery_codes_survive_app_key_rotation(): void
    {
        $admin = User::factory()->admin()->create();
        [, $code] = app(GiftCardService::class)->create(1000, 'USD', $admin);
        $user = User::factory()->withTwoFactor()->create();
        $recovery = app(TwoFactorService::class)->regenerateRecoveryCodes($user);

        $oldKey = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$oldKey]]);

        app(GiftCardService::class)->redeem($user, $code);
        $this->assertSame(1000, $user->fresh()->balance_minor);
        $this->assertTrue(app(TwoFactorService::class)->verify($user->fresh(), $recovery[0]));
    }
}
