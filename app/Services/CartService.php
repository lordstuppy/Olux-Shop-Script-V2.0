<?php

namespace App\Services;

use App\Exceptions\UserFacingException;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Contracts\Session\Session;

/**
 * Session-backed cart. The session only stores product ids and quantities;
 * prices are always recalculated from the database on the server.
 */
class CartService
{
    private const ITEMS_KEY = 'cart.items';

    private const CURRENCY_KEY = 'cart.currency';

    public function __construct(
        private readonly Session $session,
        private readonly CurrencyConverter $converter,
    ) {}

    public function add(Product $product, int $quantity = 1): void
    {
        if (! $product->isPurchasable()) {
            throw new UserFacingException(__('":title" is not available for purchase right now.', ['title' => $product->title]));
        }

        $items = $this->rawItems();
        if (! isset($items[$product->id]) && count($items) >= (int) config('shop.max_cart_lines')) {
            throw new UserFacingException(__('Your cart is full. Check out or remove an item before adding more.'));
        }

        $this->setQuantity($product, ($items[$product->id] ?? 0) + $quantity);
    }

    public function update(Product $product, int $quantity): void
    {
        if ($quantity <= 0) {
            $this->remove($product->id);

            return;
        }
        $this->setQuantity($product, $quantity);
    }

    public function remove(int $productId): void
    {
        $items = $this->rawItems();
        unset($items[$productId]);
        $this->session->put(self::ITEMS_KEY, $items);
    }

    public function clear(): void
    {
        $this->session->forget(self::ITEMS_KEY);
    }

    /** @return array<int, int> product id => quantity */
    public function rawItems(): array
    {
        $items = $this->session->get(self::ITEMS_KEY, []);

        return is_array($items) ? array_map('intval', $items) : [];
    }

    public function count(): int
    {
        return array_sum($this->rawItems());
    }

    public function isEmpty(): bool
    {
        return $this->rawItems() === [];
    }

    public function currency(): string
    {
        $currency = $this->session->get(self::CURRENCY_KEY);

        return is_string($currency) && Money::isSupported($currency) ? $currency : (string) config('shop.default_currency');
    }

    public function setCurrency(string $currency): void
    {
        if (! Money::isSupported($currency)) {
            throw new UserFacingException(__(':currency is not a supported currency. Choose one of: :options.', ['currency' => $currency, 'options' => implode(', ', Money::supported())]));
        }
        $this->session->put(self::CURRENCY_KEY, $currency);
    }

    /**
     * Prices every line in the cart currency.
     *
     * @return array{
     *     currency: string,
     *     lines: list<array{product: Product, quantity: int, unit_minor: int, line_minor: int, rate: string}>,
     *     subtotal_minor: int,
     *     problems: list<string>
     * }
     */
    public function totals(): array
    {
        $currency = $this->currency();
        $items = $this->rawItems();
        $products = Product::query()->with('category')->whereIn('id', array_keys($items))->get()->keyBy('id');

        $lines = [];
        $problems = [];
        $subtotal = 0;
        foreach ($items as $productId => $quantity) {
            $product = $products->get($productId);
            if ($product === null || ! $product->isPurchasable()) {
                $problems[] = $product
                    ? __('":title" is no longer available and was removed from your cart.', ['title' => $product->title])
                    : __('An item in your cart no longer exists and was removed.');
                $this->remove($productId);

                continue;
            }
            if ($product->stock !== null && $quantity > $product->stock) {
                $problems[] = __('Only :stock of ":title" left; your quantity was reduced.', ['stock' => $product->stock, 'title' => $product->title]);
                $quantity = $product->stock;
                $this->session->put(self::ITEMS_KEY.'.'.$productId, $quantity);
            }

            try {
                [$unit, $rate] = $this->converter->convert($product->price_minor, $product->currency, $currency);
            } catch (UserFacingException $e) {
                $problems[] = __('":title" is priced in :product_currency and cannot be bought in :currency. Switch the cart currency to :product_currency.', ['title' => $product->title, 'product_currency' => $product->currency, 'currency' => $currency]);

                continue;
            }

            $lineMinor = $unit * $quantity;
            $subtotal += $lineMinor;
            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_minor' => $unit,
                'line_minor' => $lineMinor,
                'rate' => $rate,
            ];
        }

        return [
            'currency' => $currency,
            'lines' => $lines,
            'subtotal_minor' => $subtotal,
            'problems' => $problems,
        ];
    }

    private function setQuantity(Product $product, int $quantity): void
    {
        $max = (int) config('shop.max_quantity_per_line');
        if ($quantity > $max) {
            throw new UserFacingException(__('You can buy at most :max of ":title" per order.', ['max' => $max, 'title' => $product->title]));
        }
        if ($product->stock !== null && $quantity > $product->stock) {
            throw new UserFacingException(__('Only :stock of ":title" left in stock.', ['stock' => $product->stock, 'title' => $product->title]));
        }

        $items = $this->rawItems();
        $items[$product->id] = $quantity;
        $this->session->put(self::ITEMS_KEY, $items);
    }
}
