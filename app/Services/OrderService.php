<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\UserFacingException;
use App\Mail\SellerSaleMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        private readonly CurrencyConverter $converter,
        private readonly CouponService $coupons,
        private readonly PayoutService $payouts,
        private readonly AuditLogger $audit,
        private readonly CommissionService $commission,
    ) {}

    /**
     * Creates a pending order from the cart contents. Stock and the coupon
     * redemption are reserved under row locks in the same transaction.
     *
     * Submitting the same idempotency key twice returns the first order.
     *
     * @param  array<int, int>  $items  product id => quantity
     */
    public function createFromCart(User $buyer, array $items, string $currency, string $idempotencyKey, ?string $couponCode = null): Order
    {
        if (! preg_match('/^[A-Za-z0-9-]{16,64}$/', $idempotencyKey)) {
            throw new UserFacingException(__('The checkout form has expired. Reload the checkout page and submit it again.'));
        }

        $existing = $this->findByIdempotencyKey($buyer, $idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }
        if ($items === []) {
            throw new UserFacingException(__('Your cart is empty.'));
        }
        if (! Money::isSupported($currency)) {
            throw new UserFacingException(__(':currency is not a supported currency.', ['currency' => $currency]));
        }

        try {
            $order = DB::transaction(fn () => $this->createLocked($buyer, $items, $currency, $idempotencyKey, $couponCode));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key won the race.
            $existing = $this->findByIdempotencyKey($buyer, $idempotencyKey);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        // After commit: a balance checkout wraps this in a transaction that may still roll back.
        DB::afterCommit(fn () => Log::info('Order {public_id} created with status pending for {total}', [
            'public_id' => $order->public_id,
            'total' => Money::format($order->total_minor, $order->currency),
        ]));

        return $order;
    }

    public function transition(Order $order, OrderStatus $next): void
    {
        $current = $order->status;
        if (! $current->canTransitionTo($next)) {
            throw InvalidOrderTransition::make($order->public_id, $current, $next);
        }

        $order->status = $next;
        match ($next) {
            OrderStatus::Paid => $order->paid_at = now(),
            OrderStatus::Delivered => $order->delivered_at = now(),
            default => null,
        };
        $order->save();

        Log::info('Order {public_id} transitioned from {from} to {to}', [
            'public_id' => $order->public_id,
            'from' => $current->value,
            'to' => $next->value,
        ]);
    }

    /**
     * Moves a pending order to paid and books seller earnings. Must run inside
     * a transaction that holds a lock on the order row.
     *
     * Returns false without changing anything when the order is already paid,
     * which makes repeated payment notifications harmless.
     */
    public function markPaid(Order $order): bool
    {
        if ($order->status->isPaidState()) {
            return false;
        }

        $this->transition($order, OrderStatus::Paid);
        foreach ($order->items as $item) {
            $this->payouts->recordSale($item, $order->currency);
        }
        $this->audit->log('order.paid', $order, ['total' => Money::format($order->total_minor, $order->currency)], $order->buyer);

        foreach ($order->items->pluck('seller_id')->unique() as $sellerId) {
            $seller = User::find($sellerId);
            if ($seller !== null) {
                Mail::to($seller)->queue((new SellerSaleMail($order, $seller))->afterCommit());
            }
        }

        return true;
    }

    /** Buyer-initiated cancellation of an unpaid order. */
    public function cancel(Order $order, User $actor): void
    {
        DB::transaction(function () use ($order, $actor) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== OrderStatus::Pending) {
                throw new UserFacingException(__('Order :order is :status and can no longer be cancelled.', ['order' => $locked->shortId(), 'status' => mb_strtolower($locked->status->label())]));
            }
            if ($this->hasIncomingPayment($locked)) {
                throw new UserFacingException(__('Order :order has a payment in progress and cannot be cancelled. Open a support ticket if you need help.', ['order' => $locked->shortId()]));
            }
            $this->transition($locked, OrderStatus::Cancelled);
            $this->releaseReservations($locked);
            $this->audit->log('order.cancelled', $locked, [], $actor);
            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Minimum and maximum order totals from the gateway settings, in the
     * default currency. Totals in other currencies are converted with the
     * configured rate; without a rate the limits cannot be compared and are
     * not applied. Free (fully discounted) orders are always allowed.
     */
    private function assertWithinOrderLimits(int $totalMinor, string $currency): void
    {
        $min = (int) config('shop.order_min_minor');
        $max = (int) config('shop.order_max_minor');
        if (($min === 0 && $max === 0) || $totalMinor === 0) {
            return;
        }
        $base = (string) config('shop.default_currency');
        if ($currency !== $base) {
            if ($this->converter->rate($currency, $base) === null) {
                return;
            }
            $totalMinor = $this->converter->convert($totalMinor, $currency, $base)[0];
        }
        if ($min > 0 && $totalMinor < $min) {
            throw new UserFacingException(__('The minimum order is :amount. Add more to your cart to check out.', ['amount' => Money::format($min, $base)]));
        }
        if ($max > 0 && $totalMinor > $max) {
            throw new UserFacingException(__('Orders above :amount cannot be placed online. Split your order or contact support.', ['amount' => Money::format($max, $base)]));
        }
    }

    /**
     * Expires unpaid orders past their deadline and releases stock and coupon
     * reservations. Orders with a partial payment are left for staff review.
     */
    public function expireStale(): int
    {
        $expired = 0;
        $candidates = Order::query()->where('status', OrderStatus::Pending->value)
            ->where('expires_at', '<', now())->orderBy('id')->limit(500)->pluck('id');

        foreach ($candidates as $orderId) {
            $expired += DB::transaction(function () use ($orderId) {
                $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
                if ($order === null || $order->status !== OrderStatus::Pending || $this->hasIncomingPayment($order)) {
                    return 0;
                }
                $this->transition($order, OrderStatus::Expired);
                $this->releaseReservations($order);
                $this->audit->log('order.expired', $order);

                return 1;
            });
        }

        return $expired;
    }

    private function createLocked(User $buyer, array $items, string $currency, string $idempotencyKey, ?string $couponCode): Order
    {
        // Serialise checkouts per buyer, then cap unpaid orders so nobody can
        // hoard stock or coupon redemptions with orders they never pay.
        User::query()->whereKey($buyer->id)->lockForUpdate()->first();
        $maxOpen = (int) config('shop.max_open_orders');
        $open = Order::query()->where('buyer_id', $buyer->id)->where('status', OrderStatus::Pending->value)->count();
        if ($open >= $maxOpen) {
            throw new UserFacingException(trans_choice('{1} You have :count unpaid order. Pay or cancel one in Your orders before placing a new one.|[0,*] You have :count unpaid orders. Pay or cancel one in Your orders before placing a new one.', $open));
        }

        ksort($items);
        // Products with limited stock are locked exclusively (stock is decremented
        // below), in a stable order (by id) so concurrent checkouts cannot deadlock.
        // Unlimited products only get a shared lock: they cannot change while the
        // order is written, but buyers of the same product do not queue behind each other.
        $limited = Product::query()->whereIn('id', array_keys($items))->whereNotNull('stock')->orderBy('id')->lockForUpdate()->get();
        $unlimited = Product::query()->whereIn('id', array_keys($items))->whereNull('stock')->orderBy('id')->sharedLock()->get();
        $products = $limited->concat($unlimited)->keyBy('id');
        if ($products->count() !== Product::query()->whereIn('id', array_keys($items))->count()) {
            // A product switched between unlimited and limited stock between the two reads.
            throw new UserFacingException(__('A product in your cart changed a moment ago. Review your cart and place the order again.'));
        }
        $products->load('category');
        $profiles = SellerProfile::query()->whereIn('user_id', $products->pluck('seller_id'))->get()->keyBy('user_id');

        $lines = [];
        $subtotal = 0;
        foreach ($items as $productId => $quantity) {
            $quantity = (int) $quantity;
            $product = $products->get($productId);
            if ($product !== null && $product->status === ProductStatus::Active && $product->isSoldOut()) {
                throw new UserFacingException(__('Sorry, ":title" just sold out. Remove it from your cart to continue.', ['title' => $product->title]));
            }
            if ($product === null || ! $product->isPurchasable()) {
                $title = $product?->title ?? "Product #{$productId}";
                throw new UserFacingException(__('":title" is no longer available. Remove it from your cart to continue.', ['title' => $title]));
            }
            if ($product->seller_id === $buyer->id) {
                throw new UserFacingException(__('You cannot buy your own product ":title".', ['title' => $product->title]));
            }
            if ($quantity < 1 || $quantity > (int) config('shop.max_quantity_per_line')) {
                throw new UserFacingException(__('Invalid quantity for ":title".', ['title' => $product->title]));
            }
            if ($product->stock !== null && $product->stock < $quantity) {
                throw new UserFacingException(__('Only :stock of ":title" left in stock; you asked for :quantity.', ['stock' => $product->stock, 'title' => $product->title, 'quantity' => $quantity]));
            }

            [$unit, $rate] = $this->converter->convert($product->price_minor, $product->currency, $currency);
            $gross = $unit * $quantity;
            $subtotal += $gross;
            $lines[] = compact('product', 'quantity', 'unit', 'rate', 'gross');
        }

        $coupon = null;
        $discount = 0;
        if ($couponCode !== null && trim($couponCode) !== '') {
            [$coupon, $discount] = $this->coupons->reserve($couponCode, $currency, $subtotal, $buyer);
        }

        $this->assertWithinOrderLimits($subtotal - $discount, $currency);

        $order = Order::create([
            'public_id' => (string) Str::uuid(),
            'buyer_id' => $buyer->id,
            'status' => OrderStatus::Pending,
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'total_minor' => $subtotal - $discount,
            'currency' => $currency,
            'coupon_id' => $coupon?->id,
            'idempotency_key' => $idempotencyKey,
            'expires_at' => now()->addMinutes((int) config('shop.order_ttl_minutes')),
        ]);

        $discounts = Money::allocate($discount, array_column($lines, 'gross'));
        foreach ($lines as $i => $line) {
            /** @var Product $product */
            $product = $line['product'];
            $order->items()->create([
                'product_id' => $product->id,
                'seller_id' => $product->seller_id,
                'title' => $product->title,
                'unit_price_minor' => $line['unit'],
                'quantity' => $line['quantity'],
                'list_price_minor' => $product->price_minor,
                'list_currency' => $product->currency,
                'fx_rate' => $line['rate'],
                'discount_minor' => $discounts[$i],
                'commission_bps' => $this->commission->resolve($product, $profiles->get($product->seller_id))['bps'],
            ]);

            if ($product->stock !== null) {
                $product->decrement('stock', $line['quantity']);
            }
        }

        $this->audit->log('order.created', $order, [
            'total' => Money::format($order->total_minor, $currency),
            'coupon' => $coupon?->code,
        ], $buyer);

        return $order->load('items');
    }

    private function releaseReservations(Order $order): void
    {
        foreach ($order->items()->get() as $item) {
            Product::query()->whereKey($item->product_id)->whereNotNull('stock')->increment('stock', $item->quantity);
        }
        $this->coupons->release($order);
    }

    private function hasIncomingPayment(Order $order): bool
    {
        return $order->charges()->whereIn('status', [PaymentStatus::Partial->value, PaymentStatus::Confirmed->value])->exists();
    }

    private function findByIdempotencyKey(User $buyer, string $key): ?Order
    {
        return Order::query()->where('buyer_id', $buyer->id)->where('idempotency_key', $key)->first();
    }
}
