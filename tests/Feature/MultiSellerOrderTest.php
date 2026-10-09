<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\DeliverOrder;
use App\Mail\SellerSaleMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductLicenseKey;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use Illuminate\Support\Facades\DB;
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

    public function test_one_sellers_delivery_failure_does_not_block_the_others(): void
    {
        Mail::fake();
        $working = $this->instantProductWithFile(['seller_id' => User::factory()->seller()->create()->id, 'price_minor' => 1000, 'title' => 'Works fine']);
        // A key product whose keys ran out between checkout and delivery.
        $broken = Product::factory()->create(['seller_id' => User::factory()->seller()->create()->id, 'price_minor' => 1000, 'title' => 'Out of keys']);
        $broken->licenseKeys()->create(['key_encrypted' => 'K-1', 'key_fingerprint' => hash('sha256', 'K-1')]);
        $buyer = User::factory()->withBalance(5000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$working->id => 1, $broken->id => 1], 'USD', (string) Str::uuid());
        ProductLicenseKey::query()->where('product_id', $broken->id)->update(['order_item_id' => null, 'assigned_at' => null]);
        ProductLicenseKey::query()->where('product_id', $broken->id)->delete();
        app(PaymentService::class)->payWithBalance($order, $buyer);
        DeliverOrder::dispatchSync($order->id);

        $items = $order->items()->get()->keyBy('title');
        $this->assertNotNull($items['Works fine']->delivered_at);
        $this->assertNull($items['Out of keys']->delivered_at);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'delivery.failed']);
        $this->assertSame(1, DB::table('seller_ledger_entries')->where('type', 'sale')->where('order_item_id', $items['Works fine']->id)->count());
    }
}
