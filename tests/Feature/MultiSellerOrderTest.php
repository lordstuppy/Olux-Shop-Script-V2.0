<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Mail\SellerSaleMail;
use App\Models\Order;
use App\Models\User;
use App\Services\PayoutService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiSellerOrderTest extends TestCase
{
    public function test_one_cart_with_products_from_two_sellers(): void
    {
        Mail::fake();
        config(['shop.commission_bps' => 1000, 'shop.payout_hold_days' => 0]);
        $first = User::factory()->seller()->create();
        $second = User::factory()->seller()->create();
        $a = $this->instantProductWithFile(['seller_id' => $first->id, 'price_minor' => 3000]);
        $b = $this->instantProductWithFile(['seller_id' => $second->id, 'price_minor' => 1000, 'commission_bps' => 2000]);
        $buyer = User::factory()->withBalance(10000)->create();

        $this->actingAs($buyer);
        $this->postForm('/cart/items', ['product_id' => $a->id]);
        $this->postForm('/cart/items', ['product_id' => $b->id, 'quantity' => 2]);
        $this->get('/cart')->assertSee($a->title)->assertSee($b->title)->assertSee('50.00 USD');
        $this->postForm('/checkout', ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'balance', 'accept_terms' => '1']);

        $order = Order::with('items')->firstOrFail();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame(5000, $order->total_minor);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $order->items->pluck('seller_id')->unique()->all());

        // Each seller earns from their own line at their own rate, and gets their own sale email.
        $payouts = app(PayoutService::class);
        $this->assertSame(2700, $payouts->balances($first)['USD']['available']);
        $this->assertSame(1600, $payouts->balances($second)['USD']['available']);
        Mail::assertQueued(SellerSaleMail::class, fn ($m) => $m->hasTo($first->email));
        Mail::assertQueued(SellerSaleMail::class, fn ($m) => $m->hasTo($second->email));
        $this->assertSame([], $payouts->reconcile());

        // An order-level refund is shared by both lines in proportion (30:20).
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->confirmPassword();
        $this->postForm(route('admin.orders.refund', $order), ['amount' => '10.00', 'method' => 'balance'])->assertSessionHas('success');
        $this->assertSame([600, 400], $order->items()->orderBy('id')->pluck('refunded_minor')->all());
        $this->assertSame([], $payouts->reconcile());
    }
}
