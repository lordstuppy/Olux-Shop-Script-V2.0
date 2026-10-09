<?php

namespace Tests\Unit;

use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\UserFacingException;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerLedgerEntry;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = app(OrderService::class);
    }

    private function create(User $buyer, array $items, string $key = 'key-0000000000000001', ?string $coupon = null): Order
    {
        return $this->orders->createFromCart($buyer, $items, 'USD', $key, $coupon);
    }

    public function test_creates_pending_order_and_reserves_stock(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->price(1500)->stock(5)->create();

        $order = $this->create($buyer, [$product->id => 2]);

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(3000, $order->total_minor);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(1500, $order->items->first()->unit_price_minor);
        $this->assertNotNull($order->expires_at);
    }

    public function test_same_idempotency_key_returns_same_order(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->stock(5)->create();

        $first = $this->create($buyer, [$product->id => 1]);
        $second = $this->create($buyer, [$product->id => 1]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(4, $product->fresh()->stock);
    }

    public function test_rejects_quantity_above_stock(): void
    {
        $product = Product::factory()->stock(1)->create();
        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage('Only 1');
        $this->create(User::factory()->create(), [$product->id => 2]);
    }

    public function test_seller_cannot_buy_own_product(): void
    {
        $product = Product::factory()->create();
        $this->expectException(UserFacingException::class);
        $this->create($product->seller, [$product->id => 1]);
    }

    public function test_allowed_and_forbidden_transitions(): void
    {
        $order = $this->create(User::factory()->create(), [Product::factory()->create()->id => 1]);

        $this->orders->transition($order, OrderStatus::Paid);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);

        $this->expectException(InvalidOrderTransition::class);
        $this->orders->transition($order, OrderStatus::Pending);
    }

    public function test_expired_order_cannot_become_paid(): void
    {
        $order = $this->create(User::factory()->create(), [Product::factory()->create()->id => 1]);
        $this->orders->transition($order, OrderStatus::Expired);

        $this->expectException(InvalidOrderTransition::class);
        $this->orders->transition($order, OrderStatus::Paid);
    }

    public function test_mark_paid_is_idempotent(): void
    {
        $order = $this->create(User::factory()->create(), [Product::factory()->price(2000)->create()->id => 1]);

        $first = DB::transaction(fn () => $this->orders->markPaid(Order::lockForUpdate()->find($order->id)));
        $second = DB::transaction(fn () => $this->orders->markPaid(Order::lockForUpdate()->find($order->id)));

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        // One sale and one commission entry, not two of each.
        $this->assertSame(2, SellerLedgerEntry::count());
        $this->assertSame(1800, (int) SellerLedgerEntry::sum('amount_minor'));
    }

    public function test_expire_stale_releases_stock_and_coupon(): void
    {
        Coupon::create(['code' => 'TEN', 'type' => CouponType::Percent, 'value' => 1000, 'active' => true]);
        $product = Product::factory()->stock(3)->price(1000)->create();
        $order = $this->create(User::factory()->create(), [$product->id => 2], 'key-0000000000000002', 'ten');

        $this->assertSame(100 * 2, $order->discount_minor);
        $this->assertSame(1, Coupon::first()->redemptions_count);

        $order->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, $this->orders->expireStale());

        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(0, Coupon::first()->redemptions_count);
    }

    public function test_discount_is_allocated_across_lines(): void
    {
        Coupon::create(['code' => 'FIVE', 'type' => CouponType::Fixed, 'value' => 500, 'currency' => 'USD', 'active' => true]);
        $a = Product::factory()->price(1000)->create();
        $b = Product::factory()->price(3000)->create();

        $order = $this->create(User::factory()->create(), [$a->id => 1, $b->id => 1], 'key-0000000000000003', 'FIVE');

        $this->assertSame(3500, $order->total_minor);
        $this->assertSame([125, 375], $order->items->sortBy('product_id')->pluck('discount_minor')->values()->all());
    }

    public function test_cancel_releases_reservation(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->stock(2)->create();
        $order = $this->create($buyer, [$product->id => 2]);

        $this->orders->cancel($order, $buyer);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(2, $product->fresh()->stock);
    }
}
