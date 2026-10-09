<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderFiltersTest extends TestCase
{
    private function order(User $seller, string $buyerEmail, int $daysAgo): Order
    {
        $this->travel(-$daysAgo)->days();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 1000]);
        $buyer = User::factory()->withBalance(5000)->create(['email' => $buyerEmail]);
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $this->travelBack();

        return $order->fresh();
    }

    public function test_filter_orders_by_seller_buyer_and_date_range(): void
    {
        $alpha = User::factory()->seller()->create();
        $beta = User::factory()->seller()->create();
        $a = $this->order($alpha, 'anna@example.test', 20);
        $b = $this->order($beta, 'bob@example.test', 5);
        $c = $this->order($alpha, 'carla@example.test', 1);

        $this->actingAs(User::factory()->staff(UserRole::Finance)->create())->confirmPassword();
        $this->get(route('admin.orders.index', ['seller' => $alpha->id]))->assertOk()
            ->assertSee($a->shortId())->assertSee($c->shortId())->assertDontSee($b->shortId());
        $this->get(route('admin.orders.index', ['buyer' => 'BOB@']))->assertSee($b->shortId())->assertDontSee($a->shortId());
        $this->get(route('admin.orders.index', ['from' => now()->subDays(10)->toDateString(), 'to' => now()->subDays(2)->toDateString()]))
            ->assertSee($b->shortId())->assertDontSee($a->shortId())->assertDontSee($c->shortId());
        $this->get(route('admin.orders.index', ['from' => '2026-02-30']))->assertSessionHasErrors('from');

        // The bulk export follows the same filters.
        $csv = $this->postForm(route('admin.bulk.orders.export'), ['scope' => 'filtered', 'filter_seller' => $alpha->id, 'filter_from' => now()->subDays(3)->toDateString()])->streamedContent();
        $this->assertStringContainsString($c->public_id, $csv);
        $this->assertStringNotContainsString($a->public_id, $csv);
        $this->assertStringNotContainsString($b->public_id, $csv);
    }

    public function test_users_export_has_no_secrets(): void
    {
        $user = User::factory()->withTwoFactor()->create(['email' => 'export-me@example.test']);
        $this->actingAs(User::factory()->admin()->create())->confirmPassword();
        $csv = $this->get(route('admin.exports.download', ['type' => 'users', 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]))->assertOk()->streamedContent();

        $this->assertStringStartsWith('id,email,name,role,status,email_verified,two_factor,currency,balance,paid_orders,created_at', $csv);
        $this->assertStringContainsString('export-me@example.test', $csv);
        $this->assertStringNotContainsString($user->password_hash, $csv);
        $this->assertStringNotContainsString('$argon2', $csv);
        $this->assertStringNotContainsString((string) $user->getRawOriginal('two_factor_secret'), $csv);
    }
}
