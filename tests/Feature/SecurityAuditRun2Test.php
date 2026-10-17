<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Coupon;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression tests for the second security-audit run: staff self-use of
 * coupons (SA-03) and exchange rates (SA-04). Both let a finance or admin
 * user take goods for free at a third-party seller's expense.
 */
class SecurityAuditRun2Test extends TestCase
{
    public function test_sa03_staff_cannot_use_a_coupon_they_created(): void
    {
        $finance = User::factory()->staff(UserRole::Finance)->create();
        $product = $this->instantProductWithFile(['price_minor' => 2000]);
        $this->actingAs($finance)->confirmPassword();
        $this->postForm(route('admin.coupons.store'), ['code' => 'FREE100', 'type' => 'percent', 'value' => '100'])->assertSessionHas('success');
        $this->assertSame($finance->id, Coupon::query()->sole()->created_by);

        $this->postForm('/cart/items', ['product_id' => $product->id]);
        $this->postForm(route('checkout.coupon'), ['code' => 'free100'])
            ->assertSessionHas('error', 'This concerns your own account, order, sale or payout. Another team member has to do it.');
        // Not even with the code put into the checkout form directly.
        $this->postForm(route('checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'balance', 'accept_terms' => '1', 'coupon' => 'FREE100']);
        $this->assertSame(0, Order::query()->where('buyer_id', $finance->id)->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Delivered->value])->count());

        // Anyone else can use it.
        $buyer = User::factory()->create();
        $this->actingAs($buyer)->postForm('/cart/items', ['product_id' => $product->id]);
        $this->postForm(route('checkout.coupon'), ['code' => 'FREE100'])->assertSessionHas('success');
    }

    public function test_sa03_creating_a_coupon_needs_a_recent_password(): void
    {
        $this->actingAs(User::factory()->staff(UserRole::Finance)->create());
        $this->postForm(route('admin.coupons.store'), ['code' => 'NOCONFIRM', 'type' => 'percent', 'value' => '10'])
            ->assertRedirect(route('password.confirm'));
        $this->assertSame(0, Coupon::query()->count());
    }

    public function test_sa04_a_positive_price_never_converts_to_zero(): void
    {
        ExchangeRate::create(['base' => 'USD', 'quote' => 'EUR', 'rate' => '0.0000000001']);
        $product = $this->instantProductWithFile(['price_minor' => 5000, 'currency' => 'USD']);
        $buyer = User::factory()->create(['currency' => 'EUR']);
        $this->actingAs($buyer)->postForm('/cart/currency', ['currency' => 'EUR']);
        $this->postForm('/cart/items', ['product_id' => $product->id]);

        $this->get('/cart')->assertOk()->assertSee('cannot be bought in EUR');
        $this->get('/products/'.$product->slug)->assertOk();
        $this->postForm(route('checkout.store'), ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'balance', 'accept_terms' => '1']);
        $this->assertSame(0, Order::query()->count(), 'an order was created at a price of zero');
    }

    public function test_sa04_a_rate_can_move_at_most_30_percent_within_a_day(): void
    {
        ExchangeRate::create(['base' => 'USD', 'quote' => 'EUR', 'rate' => '0.92']);
        $this->actingAs(User::factory()->staff(UserRole::Finance)->create())->confirmPassword();
        $set = fn (string $rate) => $this->postForm(route('admin.rates.store'), ['base' => 'USD', 'quote' => 'EUR', 'rate' => $rate]);

        $set('0.0000000001')->assertSessionHasErrors('rate');
        $set('5')->assertSessionHasErrors('rate');
        $set('0.70')->assertSessionHas('success');
        // Steps are measured from the rate at the start of the 24-hour window,
        // so small steps cannot walk the rate down within a day.
        $set('0.60')->assertSessionHasErrors('rate');
        $this->assertSame('0.7000000000', (string) ExchangeRate::query()->sole()->rate);

        $this->travel(25)->hours();
        $this->confirmPassword();
        $set('0.55')->assertSessionHas('success');
        // A pair without a rate can be set to any value.
        $set = fn (string $rate) => $this->postForm(route('admin.rates.store'), ['base' => 'EUR', 'quote' => 'USD', 'rate' => $rate]);
        $set('1.0870')->assertSessionHas('success');
    }
}
