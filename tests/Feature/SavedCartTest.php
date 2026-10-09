<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SavedCartTest extends TestCase
{
    public function test_cart_of_a_signed_in_buyer_survives_logout_and_login(): void
    {
        $buyer = $this->buyer();
        $product = Product::factory()->create(['title' => 'Kept Product', 'price_minor' => 1500]);

        $this->postForm('/login', ['email' => $buyer->email, 'password' => 'correct-horse-battery-1'])->assertRedirect();
        $this->postForm('/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertRedirect(route('cart.show'));
        $this->assertSame([(string) $product->id => 2], json_decode(DB::table('saved_carts')->where('user_id', $buyer->id)->value('items'), true));

        $this->postForm('/logout');
        $this->flushSession();
        $this->get('/cart')->assertDontSee('Kept Product');

        $product->update(['price_minor' => 1700]);
        $this->postForm('/login', ['email' => $buyer->email, 'password' => 'correct-horse-battery-1'])->assertRedirect();
        $this->get('/cart')->assertOk()->assertSee('Kept Product')->assertSee('34.00 USD');
    }

    public function test_guest_cart_is_merged_with_the_saved_cart_at_login(): void
    {
        $buyer = $this->buyer();
        $saved = Product::factory()->create(['title' => 'Saved Earlier']);
        $guestPick = Product::factory()->create(['title' => 'Picked As Guest']);
        DB::table('saved_carts')->insert(['user_id' => $buyer->id, 'items' => json_encode([$saved->id => 1]), 'currency' => 'USD', 'updated_at' => now()]);

        $this->postForm('/cart/items', ['product_id' => $guestPick->id])->assertRedirect(route('cart.show'));
        $this->postForm('/login', ['email' => $buyer->email, 'password' => 'correct-horse-battery-1'])->assertRedirect();

        $this->get('/cart')->assertSee('Saved Earlier')->assertSee('Picked As Guest');
        $this->assertCount(2, json_decode(DB::table('saved_carts')->where('user_id', $buyer->id)->value('items'), true));
    }

    public function test_sold_out_items_are_flagged_and_checkout_clears_the_saved_cart(): void
    {
        $buyer = $this->buyer(['balance_minor' => 10000]);
        $soldOut = Product::factory()->create(['title' => 'Last One', 'stock' => 1]);
        $other = Product::factory()->create(['title' => 'Plenty', 'price_minor' => 1000]);
        $this->actingAs($buyer);
        $this->postForm('/cart/items', ['product_id' => $soldOut->id]);
        $this->postForm('/cart/items', ['product_id' => $other->id]);
        $soldOut->update(['stock' => 0]);

        $this->get('/cart')->assertSee('"Last One" is sold out and was removed from your cart.');

        $this->postForm('/checkout', ['idempotency_key' => 'saved-cart-test-key-1', 'payment_method' => 'balance', 'accept_terms' => '1'])->assertRedirect();
        $this->assertDatabaseMissing('saved_carts', ['user_id' => $buyer->id]);
    }

    public function test_old_saved_carts_are_ignored_and_pruned(): void
    {
        $buyer = $this->buyer();
        $product = Product::factory()->create(['title' => 'Long Forgotten']);
        DB::table('saved_carts')->insert(['user_id' => $buyer->id, 'items' => json_encode([$product->id => 1]), 'currency' => 'USD', 'updated_at' => now()->subDays(31)]);

        $this->postForm('/login', ['email' => $buyer->email, 'password' => 'correct-horse-battery-1'])->assertRedirect();
        $this->get('/cart')->assertDontSee('Long Forgotten');

        DB::table('saved_carts')->insert(['user_id' => $this->buyer()->id, 'items' => '{}', 'currency' => null, 'updated_at' => now()->subDays(40)]);
        $this->artisan('shop:prune-saved-carts')->assertSuccessful();
        $this->assertSame(0, DB::table('saved_carts')->where('updated_at', '<', now()->subDays(30))->count());
    }
}
