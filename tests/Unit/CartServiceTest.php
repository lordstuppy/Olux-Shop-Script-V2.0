<?php

namespace Tests\Unit;

use App\Enums\ProductStatus;
use App\Exceptions\UserFacingException;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Services\CartService;
use App\Services\CurrencyConverter;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

class CartServiceTest extends TestCase
{
    private CartService $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cart = new CartService(new Store('test', new ArraySessionHandler(120)), new CurrencyConverter);
    }

    public function test_add_merges_quantities_and_counts_items(): void
    {
        $product = Product::factory()->create();
        $this->cart->add($product, 1);
        $this->cart->add($product, 2);

        $this->assertSame([$product->id => 3], $this->cart->rawItems());
        $this->assertSame(3, $this->cart->count());
    }

    public function test_update_sets_quantity_and_zero_removes(): void
    {
        $product = Product::factory()->create();
        $this->cart->add($product, 2);
        $this->cart->update($product, 5);
        $this->assertSame([$product->id => 5], $this->cart->rawItems());

        $this->cart->update($product, 0);
        $this->assertTrue($this->cart->isEmpty());
    }

    public function test_remove_deletes_line(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $this->cart->add($a);
        $this->cart->add($b);
        $this->cart->remove($a->id);

        $this->assertSame([$b->id => 1], $this->cart->rawItems());
    }

    public function test_totals_are_calculated_server_side(): void
    {
        $a = Product::factory()->price(1999)->create();
        $b = Product::factory()->price(500)->create();
        $this->cart->add($a, 2);
        $this->cart->add($b, 3);

        $totals = $this->cart->totals();
        $this->assertSame('USD', $totals['currency']);
        $this->assertSame(1999 * 2 + 500 * 3, $totals['subtotal_minor']);
        $this->assertSame(3998, $totals['lines'][0]['line_minor']);
        $this->assertSame([], $totals['problems']);
    }

    public function test_totals_convert_with_explicit_rate_rounding_half_up(): void
    {
        ExchangeRate::create(['base' => 'EUR', 'quote' => 'USD', 'rate' => '1.0875']);
        $product = Product::factory()->price(1001, 'EUR')->create();
        $this->cart->add($product, 2);

        $totals = $this->cart->totals();
        // 10.01 EUR * 1.0875 = 10.885875 USD -> 10.89 per unit.
        $this->assertSame(1089, $totals['lines'][0]['unit_minor']);
        $this->assertSame(2178, $totals['subtotal_minor']);
    }

    public function test_missing_rate_is_reported_not_guessed(): void
    {
        $product = Product::factory()->price(1000, 'EUR')->create();
        $this->cart->add($product);

        $totals = $this->cart->totals();
        $this->assertSame([], $totals['lines']);
        $this->assertStringContainsString('cannot be bought in USD', $totals['problems'][0]);
    }

    public function test_quantity_cannot_exceed_stock_or_line_limit(): void
    {
        $limited = Product::factory()->stock(2)->create();
        $this->cart->add($limited, 2);
        try {
            $this->cart->add($limited, 1);
            $this->fail('Expected stock limit');
        } catch (UserFacingException $e) {
            $this->assertStringContainsString('Only 2', $e->getMessage());
        }

        $unlimited = Product::factory()->create();
        $this->expectException(UserFacingException::class);
        $this->cart->add($unlimited, 11);
    }

    public function test_unavailable_products_are_dropped_from_totals(): void
    {
        $product = Product::factory()->create();
        $this->cart->add($product);
        $product->update(['status' => ProductStatus::Disabled]);

        $totals = $this->cart->totals();
        $this->assertSame(0, $totals['subtotal_minor']);
        $this->assertCount(1, $totals['problems']);
        $this->assertTrue($this->cart->isEmpty());
    }

    public function test_inactive_product_cannot_be_added(): void
    {
        $product = Product::factory()->status(ProductStatus::PendingReview)->create();
        $this->expectException(UserFacingException::class);
        $this->cart->add($product);
    }
}
