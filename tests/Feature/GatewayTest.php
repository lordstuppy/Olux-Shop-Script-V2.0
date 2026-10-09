<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\ShkeeperException;
use App\Exceptions\UserFacingException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Shkeeper\ShkeeperClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GatewayTest extends TestCase
{
    private function superAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->confirmPassword();

        return $admin;
    }

    public function test_super_admin_toggles_methods_coins_and_limits(): void
    {
        $this->fakeShkeeper();
        $this->superAdmin();
        $this->get(route('admin.gateway.index'))->assertOk()->assertSee('Accept cryptocurrency payments')->assertSee('Litecoin');

        $this->putForm(route('admin.gateway.update'), [
            'payments_crypto_enabled' => '1', 'crypto_enabled' => ['BTC'], 'payments_balance_enabled' => '0', 'order_min' => '5', 'order_max' => '500',
        ])->assertSessionHas('success');
        $this->assertFalse(config('shop.payments_balance_enabled'));
        $this->assertSame('LTC', config('shop.crypto_disabled'));
        $this->assertSame(500, config('shop.order_min_minor'));
        $this->assertSame(50000, config('shop.order_max_minor'));
        $this->assertDatabaseHas('audit_log', ['action' => 'gateway.settings_updated']);
        $this->assertSame(['BTC'], array_column(app(PaymentService::class)->paymentCryptos(), 'name'));

        $this->putForm(route('admin.gateway.update'), ['payments_crypto_enabled' => '1', 'crypto_enabled' => [], 'payments_balance_enabled' => '1', 'order_min' => '0', 'order_max' => '0'])
            ->assertSessionHasErrors('crypto_enabled');
        $this->putForm(route('admin.gateway.update'), ['payments_crypto_enabled' => '1', 'crypto_enabled' => ['BTC'], 'payments_balance_enabled' => '1', 'order_min' => '10', 'order_max' => '5'])
            ->assertSessionHasErrors('order_max');
        $this->putForm(route('admin.gateway.update'), ['payments_crypto_enabled' => '1', 'crypto_enabled' => ['DOGE'], 'payments_balance_enabled' => '1'])
            ->assertSessionHasErrors('crypto_enabled.0');
    }

    public function test_switched_off_methods_are_refused_at_checkout(): void
    {
        $this->fakeShkeeper();
        config(['shop.payments_balance_enabled' => false, 'shop.crypto_disabled' => 'LTC']);
        $buyer = User::factory()->withBalance(10000)->create();
        $product = $this->instantProductWithFile(['price_minor' => 1000]);
        $this->actingAs($buyer);
        $this->postForm('/cart/items', ['product_id' => $product->id]);

        $this->get('/checkout')->assertOk()->assertDontSee('Shop balance (')->assertSee('Bitcoin')->assertDontSee('Litecoin');
        $this->postForm('/checkout', ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'balance', 'accept_terms' => '1'])
            ->assertSessionHasErrors(['payment_method' => 'This payment method is switched off right now. Choose another one.']);
        $this->assertSame(0, Order::count());

        $this->postForm('/checkout', ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'crypto', 'crypto' => 'LTC', 'accept_terms' => '1'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'LTC is not accepted'));

        config(['shop.payments_crypto_enabled' => false]);
        $order = Order::firstOrFail();
        $this->get(route('orders.pay', $order))->assertSee('Crypto payments are switched off right now.');
        $this->postForm(route('orders.pay.start', $order), ['crypto' => 'BTC'])->assertSessionHas('error', 'Crypto payments are switched off right now. Pay with your shop balance or try again later.');
        $this->postForm(route('orders.pay.balance', $order))->assertSessionHas('error', 'Paying with the shop balance is switched off right now. Choose another payment method.');
    }

    public function test_order_limits_apply_in_the_default_currency(): void
    {
        config(['shop.order_min_minor' => 1000, 'shop.order_max_minor' => 5000]);
        $buyer = User::factory()->withBalance(100000)->create();
        $cheap = Product::factory()->price(500)->create();
        $dear = Product::factory()->price(6000)->create();
        $orders = app(OrderService::class);

        $this->expectsUserError(fn () => $orders->createFromCart($buyer, [$cheap->id => 1], 'USD', (string) Str::uuid()), 'The minimum order is 10.00 USD. Add more to your cart to check out.');
        $this->expectsUserError(fn () => $orders->createFromCart($buyer, [$dear->id => 1], 'USD', (string) Str::uuid()), 'Orders above 50.00 USD cannot be placed online. Split your order or contact support.');
        $this->assertSame(2000, $orders->createFromCart($buyer, [$cheap->id => 4], 'USD', (string) Str::uuid())->total_minor);
    }

    private function expectsUserError(callable $fn, string $message): void
    {
        try {
            $fn();
            $this->fail('Expected: '.$message);
        } catch (UserFacingException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_callbacks_and_api_calls_are_logged_and_shown(): void
    {
        $this->call('POST', '/webhooks/shkeeper', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertStatus(401);
        $raw = '{"external_id":"not-an-order"}';
        $headers = ShkeeperClient::signatureHeaders($raw, 'test-webhook-secret', time());
        $this->call('POST', '/webhooks/shkeeper', [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $raw)->assertStatus(202);
        $this->call('POST', '/webhooks/shkeeper', [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $raw)->assertStatus(202);

        Http::fake(['shkeeper.test/api/v1/crypto' => Http::response('down', 503)]);
        $this->superAdmin();
        $this->postForm(route('admin.gateway.check'))->assertSessionHas('error', fn ($m) => str_contains($m, 'HTTP 503'));

        $this->assertSame(['rejected_signature', 'ok', 'duplicate'], DB::table('gateway_logs')->where('channel', 'webhook')->orderBy('id')->pluck('outcome')->all());
        $this->assertDatabaseHas('gateway_logs', ['channel' => 'api', 'outcome' => 'error', 'http_status' => 503, 'action' => 'GET /api/v1/crypto']);

        $this->get(route('admin.gateway.index'))->assertOk()->assertSee('rejected signature')->assertSee('not-an-order');
        $this->get(route('admin.gateway.index', ['outcome' => 'duplicate']))->assertSee('duplicate')->assertDontSee('rejected signature');

        $this->travel(91)->days();
        $this->artisan('shop:prune-gateway-logs')->assertSuccessful();
        $this->assertSame(0, DB::table('gateway_logs')->count());
    }

    public function test_api_timeouts_are_logged_as_timeouts(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));
        try {
            app(ShkeeperClient::class)->availableCryptos();
        } catch (ShkeeperException) {
        }
        $this->assertDatabaseHas('gateway_logs', ['channel' => 'api', 'outcome' => 'timeout']);
    }

    public function test_who_sees_and_changes_the_gateway(): void
    {
        $this->fakeShkeeper();
        foreach ([UserRole::Manager, UserRole::Finance] as $role) {
            $this->actingAs(User::factory()->staff($role)->create())->confirmPassword();
            $this->get(route('admin.gateway.index'))->assertOk()->assertSee('Only a super admin can change these settings.');
            $this->putForm(route('admin.gateway.update'), ['payments_balance_enabled' => '0'])->assertForbidden();
        }
        $this->actingAs(User::factory()->staff(UserRole::Moderator)->create());
        $this->get(route('admin.gateway.index'))->assertForbidden();
        $this->assertTrue(config('shop.payments_balance_enabled'));
    }
}
