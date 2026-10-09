<?php

namespace Tests\Feature;

use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Mail\OrderDeliveredMail;
use App\Mail\TicketReplyMail;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopFeaturesTest extends TestCase
{
    public function test_coupon_applies_at_checkout(): void
    {
        $this->fakeShkeeper();
        Coupon::create(['code' => 'SAVE15', 'type' => CouponType::Percent, 'value' => 1500, 'active' => true]);
        $buyer = User::factory()->withBalance(10000)->create();
        $product = $this->instantProductWithFile(['price_minor' => 2000]);
        $this->actingAs($buyer);
        $this->postForm('/cart/items', ['product_id' => $product->id]);

        $this->postForm('/checkout/coupon', ['code' => 'save15'])->assertSessionHas('success', 'Coupon SAVE15 applied: 3.00 USD off.');
        $this->get('/checkout')->assertSee('Place order and pay 17.00 USD');
        $this->postForm('/checkout', ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'balance', 'accept_terms' => '1']);

        $order = Order::firstOrFail();
        $this->assertSame(300, $order->discount_minor);
        $this->assertSame(1700, $order->total_minor);
        $this->assertSame(1, Coupon::first()->redemptions_count);
    }

    public function test_expired_coupon_message_is_specific(): void
    {
        Coupon::create(['code' => 'OLD', 'type' => CouponType::Fixed, 'value' => 100, 'currency' => 'USD', 'expires_at' => '2026-01-01 00:00:00', 'active' => true]);
        $this->actingAs(User::factory()->create());
        $this->postForm('/cart/items', ['product_id' => Product::factory()->create()->id]);

        $this->postForm('/checkout/coupon', ['code' => 'OLD'])->assertSessionHas('error', 'Coupon OLD expired on 2026-01-01.');
    }

    public function test_gift_card_redeems_once(): void
    {
        $admin = User::factory()->admin()->create();
        [$card, $code] = app(GiftCardService::class)->create(2500, 'EUR', $admin);
        $this->assertNotSame($code, $card->code_hash);

        $user = User::factory()->create();
        $this->actingAs($user);
        $this->postForm('/wallet/redeem', ['code' => strtolower($code)])->assertSessionHas('success', 'Gift card redeemed: 25.00 EUR added to your balance.');
        $this->assertSame(2500, $user->fresh()->balance_minor);
        $this->assertSame('EUR', $user->fresh()->currency);

        $other = User::factory()->create();
        $this->actingAs($other);
        $this->postForm('/wallet/redeem', ['code' => $code])->assertSessionHas('error');
        $this->assertSame(0, $other->fresh()->balance_minor);
    }

    public function test_manual_delivery_by_seller(): void
    {
        Mail::fake();
        $seller = User::factory()->seller()->create();
        $product = Product::factory()->manual()->create(['seller_id' => $seller->id, 'price_minor' => 1000]);
        $buyer = User::factory()->withBalance(1000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);

        $item = $order->items()->first();
        $this->actingAs(User::factory()->seller()->create());
        $this->postForm(route('seller.items.deliver', $item), ['payload' => 'nope'])->assertForbidden();

        $this->actingAs($seller);
        $this->get('/seller')->assertSee($item->title);
        $this->postForm(route('seller.items.deliver', $item), ['payload' => 'Your access code: ABC-123'])->assertSessionHas('success');

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame('Your access code: ABC-123', $item->fresh()->delivered_payload['text']);
        Mail::assertQueued(OrderDeliveredMail::class);
        $this->actingAs($buyer)->get(route('orders.show', $order))->assertSee('Your access code: ABC-123');
    }

    public function test_tickets_are_private_and_staff_replies_notify(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->postForm('/tickets', ['subject' => 'Cannot download', 'body' => 'The link expired.', 'category' => 'support'])->assertRedirect();
        $ticket = Ticket::firstOrFail();

        $this->actingAs(User::factory()->create())->get(route('tickets.show', $ticket))->assertForbidden();
        $this->postForm(route('tickets.reply', $ticket), ['body' => 'hi'])->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->postForm(route('tickets.reply', $ticket), ['body' => 'Here is a fresh link.'])->assertRedirect();
        $this->assertSame(TicketStatus::Answered, $ticket->fresh()->status);
        Mail::assertQueued(TicketReplyMail::class, fn ($m) => $m->hasTo($owner->email));
    }

    public function test_ticket_about_someone_elses_order_is_refused(): void
    {
        $order = app(OrderService::class)->createFromCart(User::factory()->create(), [Product::factory()->create()->id => 1], 'USD', (string) Str::uuid());
        $this->actingAs(User::factory()->create());
        $this->postForm('/tickets', ['subject' => 'x', 'body' => 'y', 'category' => 'order_issue', 'order' => $order->public_id])->assertForbidden();
    }

    public function test_seller_onboarding_and_product_review(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->postForm('/sell', ['display_name' => 'Tools Ltd', 'payout_currency' => 'USD', 'payout_crypto' => 'BTC', 'payout_address' => 'bc1qpayoutaddress', 'accept_seller_terms' => '1'])->assertSessionHas('success');

        $profile = SellerProfile::firstOrFail();
        $this->actingAs(User::factory()->admin()->create());
        $this->postForm(route('admin.sellers.approve', $profile))->assertSessionHas('success');
        $this->assertSame(UserRole::Seller, $user->fresh()->role);

        $this->actingAs($user->fresh());
        $this->postForm(route('seller.products.store'), [
            'title' => 'Regex cheat sheet', 'description' => 'PDF', 'price' => '4.99', 'currency' => 'USD', 'delivery_type' => 'instant',
        ])->assertRedirect();
        $product = Product::firstOrFail();
        $this->assertSame(499, $product->price_minor);
        $this->assertSame(ProductStatus::Draft, $product->status);

        // Instant products need something to deliver before review.
        $this->postForm(route('seller.products.submit', $product->id))->assertSessionHas('error');
        $this->postForm(route('seller.products.keys.store', $product->id), ['keys' => "K-1\nK-2\nK-1"])
            ->assertSessionHas('success', 'Added 2 licence keys; stock increased by 2. Skipped 1 duplicates.');
        $this->postForm(route('seller.products.submit', $product->id))->assertSessionHas('success');
        $this->assertSame(ProductStatus::PendingReview, $product->fresh()->status);
        $this->get(route('products.show', $product->slug))->assertNotFound();

        $this->actingAs(User::factory()->admin()->create());
        $this->postForm(route('admin.products.status', $product->id), ['status' => 'active']);
        $this->get(route('products.show', $product->slug))->assertOk();

        // Editing reviewed fields sends it back to review.
        $this->actingAs($user->fresh());
        $this->putForm(route('seller.products.update', $product->id), [
            'title' => 'Regex cheat sheet v2', 'description' => 'PDF', 'price' => '4.99', 'currency' => 'USD', 'delivery_type' => 'instant', 'stock' => 2,
        ]);
        $this->assertSame(ProductStatus::PendingReview, $product->fresh()->status);

        // Another seller cannot edit it.
        $this->actingAs(User::factory()->seller()->create());
        $this->get(route('seller.products.edit', $product->id))->assertForbidden();
    }

    public function test_search_and_category_filters(): void
    {
        $cat = Category::create(['slug' => 'tutorials', 'name' => 'Tutorials']);
        Product::factory()->create(['title' => 'Learn PostgreSQL', 'category_id' => $cat->id]);
        Product::factory()->create(['title' => 'Vector icons']);
        Product::factory()->status(ProductStatus::Draft)->create(['title' => 'Hidden PostgreSQL draft']);

        $this->get('/products?q=postgres')->assertSee('Learn PostgreSQL')->assertDontSee('Vector icons')->assertDontSee('Hidden PostgreSQL draft');
        $this->get('/products?category=tutorials')->assertSee('Learn PostgreSQL')->assertDontSee('Vector icons');
        $this->get('/products?q=100%25')->assertOk()->assertSee('0 products found');
        $this->getJson('/search/suggest?q=lea')->assertJsonPath('results.0.title', 'Learn PostgreSQL');
    }

    public function test_seo_endpoints(): void
    {
        $product = Product::factory()->create(['title' => 'Seo product']);

        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertSee(route('products.show', $product->slug), false);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin')->assertSee('Sitemap: '.route('sitemap'));
        $this->get(route('products.show', $product->slug))
            ->assertSee('<link rel="canonical" href="'.route('products.show', $product->slug).'">', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('"@type":"Product"', false);
    }

    public function test_health_and_metrics(): void
    {
        $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->getJson('/health', ['Authorization' => 'Bearer test-metrics-token'])->assertOk()->assertJsonPath('checks.database.status', 'ok');

        $this->get('/metrics')->assertUnauthorized();
        $this->get('/metrics', ['Authorization' => 'Bearer test-metrics-token'])
            ->assertOk()->assertSee('shop_orders{status="pending"} 0', false)->assertSee('shop_exceptions_total');
    }

    public function test_audit_log_viewer_filters(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();
        $this->actingAs($admin)->confirmPassword();
        $this->postForm(route('admin.users.status', $buyer), ['status' => 'suspended'])->assertSessionHas('success');

        $this->get('/admin/audit?action=user.status')->assertOk()->assertSee('user.status_changed')->assertSee($admin->email);
        $this->get('/admin/audit?action=order.')->assertOk()->assertDontSee('user.status_changed');
    }

    public function test_currency_switch_and_conversion_snapshot(): void
    {
        ExchangeRate::create(['base' => 'USD', 'quote' => 'EUR', 'rate' => '0.92']);
        $buyer = User::factory()->withBalance(5000, 'EUR')->create();
        $product = $this->instantProductWithFile(['price_minor' => 1000]);
        $this->actingAs($buyer);
        $this->postForm('/cart/currency', ['currency' => 'EUR']);
        $this->postForm('/cart/items', ['product_id' => $product->id]);
        $this->postForm('/checkout', ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'balance', 'accept_terms' => '1']);

        $order = Order::firstOrFail();
        $this->assertSame('EUR', $order->currency);
        $this->assertSame(920, $order->total_minor);
        $item = $order->items()->first();
        $this->assertSame('0.9200000000', (string) $item->fx_rate);
        $this->assertSame(1000, $item->list_price_minor);

        // A later rate change does not touch the existing order.
        ExchangeRate::where('base', 'USD')->update(['rate' => '0.5']);
        $this->assertSame(920, $order->fresh()->total_minor);
    }
}
