<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReorderTest extends TestCase
{
    public function test_buyer_can_put_the_items_of_an_expired_order_back_in_the_cart(): void
    {
        $buyer = $this->buyer();
        $kept = Product::factory()->create(['title' => 'Still Here', 'price_minor' => 1000, 'stock' => 5]);
        $gone = Product::factory()->create(['title' => 'Sold Out Now', 'price_minor' => 500, 'stock' => 3]);
        $order = app(OrderService::class)->createFromCart($buyer, [$kept->id => 2, $gone->id => 1], 'USD', (string) Str::uuid());
        $order->update(['expires_at' => now()->subMinute()]);
        app(OrderService::class)->expireStale();
        $gone->update(['stock' => 0]);
        $kept->update(['price_minor' => 1200]);

        $this->actingAs($buyer)->get(route('orders.show', $order))->assertSee('Buy again');
        $this->postForm(route('orders.reorder', $order))->assertRedirect(route('cart.show'))
            ->assertSessionHas('error', '"Sold Out Now" is sold out.');

        $this->assertSame([$kept->id => 2], session('cart.items'));
        $this->get('/cart')->assertSee('Still Here')->assertSee('24.00 USD');
    }

    public function test_reorder_is_only_for_the_buyer_and_for_expired_or_cancelled_orders(): void
    {
        $buyer = $this->buyer();
        $product = Product::factory()->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());

        $this->actingAs($this->buyer());
        $this->postForm(route('orders.reorder', $order))->assertForbidden();

        $this->actingAs($buyer);
        $this->get(route('orders.show', $order))->assertDontSee('Buy again');
        $this->postForm(route('orders.reorder', $order))->assertRedirect(route('orders.show', $order));
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }
}
