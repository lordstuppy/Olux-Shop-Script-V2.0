<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogFiltersTest extends TestCase
{
    private function seller(string $name): User
    {
        $user = User::factory()->seller()->create();
        SellerProfile::create(['user_id' => $user->id, 'display_name' => $name, 'status' => 'approved', 'payout_currency' => 'USD',
            'payout_crypto' => 'BTC', 'payout_address' => 'bc1qtestaddress00']);

        return $user;
    }

    public function test_price_range_uses_the_shoppers_currency_across_listing_currencies(): void
    {
        ExchangeRate::create(['base' => 'EUR', 'quote' => 'USD', 'rate' => '1.10']);
        Product::factory()->price(900)->create(['title' => 'Cheap USD tool']);
        Product::factory()->price(2000)->create(['title' => 'Mid USD tool']);
        Product::factory()->price(5000)->create(['title' => 'Pricey USD tool']);
        // 20.00 EUR is 22.00 USD; 50.00 EUR is 55.00 USD.
        Product::factory()->price(2000, 'EUR')->create(['title' => 'Mid EUR guide']);
        Product::factory()->price(5000, 'EUR')->create(['title' => 'Pricey EUR guide']);

        $this->get('/products?price_min=10&price_max=22')->assertOk()
            ->assertSee('Mid USD tool')->assertSee('Mid EUR guide')
            ->assertDontSee('Cheap USD tool')->assertDontSee('Pricey USD tool')->assertDontSee('Pricey EUR guide')
            ->assertSee('2 products found.');

        $this->get('/products?price_min=50')->assertSee('Pricey USD tool')->assertSee('Pricey EUR guide')->assertDontSee('Mid EUR guide');
        // Swapped bounds are corrected rather than returning nothing.
        $this->get('/products?price_min=22&price_max=10')->assertSee('2 products found.');
        $this->get('/products?price_min=abc')->assertSessionHasErrors('price_min');
    }

    public function test_listings_in_a_currency_without_a_rate_are_left_out_of_price_filters(): void
    {
        Product::factory()->price(2000)->create(['title' => 'USD item']);
        Product::factory()->price(2000, 'EUR')->create(['title' => 'EUR item']);

        $this->get('/products?price_max=100')->assertSee('USD item')->assertDontSee('EUR item');
        $this->get('/products')->assertSee('EUR item');
    }

    public function test_seller_filter_and_more_from_this_seller(): void
    {
        $alpha = $this->seller('Alpha Studio');
        $beta = $this->seller('Beta Labs');
        $a = Product::factory()->create(['seller_id' => $alpha->id, 'title' => 'Alpha icons']);
        Product::factory()->create(['seller_id' => $beta->id, 'title' => 'Beta fonts']);

        $this->get('/products')->assertSee('Alpha Studio')->assertSee('Beta Labs');
        $this->get('/products?seller='.$alpha->id)->assertSee('Products by Alpha Studio')->assertSee('Alpha icons')->assertDontSee('Beta fonts');
        $this->get('/products/'.$a->slug)->assertSee(route('products.index', ['seller' => $alpha->id]), false);
        $this->get('/products?seller='.$alpha->id.'&q=fonts')->assertSee('0 products found.');
    }

    public function test_category_keyword_and_price_combine(): void
    {
        $category = Category::create(['name' => 'Tools', 'slug' => 'tools']);
        Product::factory()->price(1500)->create(['title' => 'Markdown converter', 'category_id' => $category->id]);
        Product::factory()->price(1500)->create(['title' => 'Markdown book']);
        Product::factory()->price(9900)->create(['title' => 'Markdown suite', 'category_id' => $category->id]);
        $slug = 'tools';

        $this->get('/products?q=markdown&category='.$slug.'&price_max=20')->assertSee('Markdown converter')->assertDontSee('Markdown book')->assertDontSee('Markdown suite');
    }

    public function test_suspended_sellers_products_are_hidden_and_not_purchasable(): void
    {
        $seller = $this->seller('Shady Shop');
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'title' => 'Shady widget', 'price_minor' => 500]);
        $buyer = User::factory()->withBalance(5000)->create();
        $this->actingAs($buyer);
        $this->postForm('/cart/items', ['product_id' => $product->id]);

        $this->get('/cart');
        $seller->forceFill(['status' => 'suspended'])->save();
        $this->get('/products')->assertDontSee('Shady widget');
        $this->get('/products')->assertDontSee('Shady Shop');
        $this->get('/products/'.$product->slug)->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee($product->slug);
        $this->postForm('/checkout', ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'balance', 'accept_terms' => '1'])
            ->assertSessionHas('error', '"Shady widget" is no longer available. Remove it from your cart to continue.');
        $this->assertSame(0, Order::count());

        $seller->forceFill(['status' => 'active'])->save();
        $this->get('/products')->assertSee('Shady widget');
    }
}
