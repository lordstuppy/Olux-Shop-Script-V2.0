<?php

namespace Tests\Feature;

use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AnalyticsService;
use App\Services\CartService;
use App\Services\CatalogService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\ShkeeperPayoutService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression tests for the performance audit (docs/performance). The query
 * budget tests load a page with a small and a larger data set and require the
 * same number of queries: a count that grows with the data is an N+1.
 */
class PerformanceAuditTest extends TestCase
{
    private function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    /** Loads $url once to warm caches, then at the current size and after $grow(). */
    private function assertQueriesDoNotGrow(string $url, callable $grow): int
    {
        $this->get($url)->assertOk();
        $small = $this->queriesFor($url);
        $grow();
        $large = $this->queriesFor($url);
        $this->assertSame($small, $large, "Queries for {$url} grow with the data: {$small} -> {$large}");

        return $large;
    }

    private function seller(): User
    {
        $seller = User::factory()->seller()->create();
        SellerProfile::create(['user_id' => $seller->id, 'display_name' => 'Shop '.$seller->id, 'status' => 'approved', 'payout_currency' => 'USD',
            'payout_crypto' => 'BTC', 'payout_address' => 'bc1qtestaddress00']);

        return $seller;
    }

    private function buy(Product $product, ?User $buyer = null, int $quantity = 1): Order
    {
        $buyer ??= User::factory()->withBalance($product->price_minor * $quantity * 2)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => $quantity], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);

        return $order->fresh();
    }

    // PERF-04: wishlist and cart checked each product's seller with its own query.
    public function test_perf04_wishlist_queries_do_not_grow_with_items(): void
    {
        $buyer = User::factory()->create();
        $this->actingAs($buyer);
        $add = fn (int $n) => Product::factory()->count($n)->create()->each(fn ($p) => $buyer->wishlistItems()->create(['product_id' => $p->id]));
        $add(2);
        $this->assertQueriesDoNotGrow(route('wishlist.index'), fn () => $add(6));
    }

    public function test_perf04_cart_queries_do_not_grow_with_lines(): void
    {
        $products = Product::factory()->count(6)->create();
        $this->postForm(route('cart.add'), ['product_id' => $products[0]->id, 'quantity' => 1]);
        $this->assertQueriesDoNotGrow(route('cart.show'), function () use ($products) {
            foreach ($products->slice(1) as $product) {
                $this->postForm(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1]);
            }
            $this->assertCount(6, app(CartService::class)->rawItems());
        });
    }

    public function test_perf04_catalog_and_product_pages_have_fixed_query_counts(): void
    {
        $category = Category::create(['slug' => 'tools', 'name' => 'Tools']);
        $product = $this->instantProductWithFile(['category_id' => $category->id]);
        Product::factory()->count(2)->create(['category_id' => $category->id]);
        $this->assertQueriesDoNotGrow('/products', fn () => Product::factory()->count(10)->create(['category_id' => $category->id]));
        $this->assertQueriesDoNotGrow('/', fn () => Product::factory()->count(5)->create());
        $review = function (int $i) use ($product) {
            $item = $this->buy($product)->items()->first();
            DB::table('product_reviews')->insert(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'order_item_id' => $item->id,
                'rating' => 4, 'title' => 'Fine '.$i, 'body' => 'Works.', 'status' => 'visible', 'created_at' => now(), 'updated_at' => now()]);
        };
        // At least one review in both states: an eager load only runs when there is something to load.
        $review(0);
        $this->assertQueriesDoNotGrow(route('products.show', $product->slug), function () use ($review) {
            foreach (range(1, 3) as $i) {
                $review($i);
            }
        });
    }

    public function test_perf04_account_and_seller_pages_have_fixed_query_counts(): void
    {
        $seller = $this->seller();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 500]);
        $buyer = User::factory()->withBalance(100000)->create();
        $this->buy($product, $buyer);
        UserNotification::query()->create(['user_id' => $buyer->id, 'kind' => 'order', 'title' => 'One']);
        $more = function () use ($product, $buyer) {
            foreach (range(1, 4) as $i) {
                $this->buy($product, $buyer);
                UserNotification::query()->create(['user_id' => $buyer->id, 'kind' => 'order', 'title' => 'More '.$i]);
            }
        };

        $this->actingAs($buyer);
        $order = $buyer->orders()->latest('id')->first();
        $this->assertQueriesDoNotGrow(route('account.orders'), $more);
        $this->assertQueriesDoNotGrow(route('notifications.index'), $more);
        $this->assertQueriesDoNotGrow(route('live', ['path' => '/orders']), $more);
        $this->assertQueriesDoNotGrow(route('orders.show', $order), $more);

        $this->actingAs($seller);
        $this->assertQueriesDoNotGrow(route('seller.dashboard'), $more);
        $this->assertQueriesDoNotGrow(route('seller.sales'), $more);
        $this->assertQueriesDoNotGrow(route('seller.products.index'), fn () => Product::factory()->count(4)->create(['seller_id' => $seller->id]));
    }

    public function test_perf04_admin_lists_have_fixed_query_counts(): void
    {
        $seller = $this->seller();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 500]);
        $this->buy($product);
        $more = function () use ($product) {
            foreach (range(1, 4) as $i) {
                $this->buy($product);
            }
        };
        $this->actingAs(User::factory()->admin()->create());
        foreach (['admin.orders.index', 'admin.products.index', 'admin.users.index', 'admin.payments.index', 'admin.audit.index', 'admin.dashboard'] as $route) {
            $this->assertQueriesDoNotGrow(route($route), $more);
        }
    }

    // PERF-02: "best selling" reads a stored figure instead of summing all order lines per view.
    public function test_perf02_sales_ranking_counts_paid_units_only(): void
    {
        $seller = $this->seller();
        $a = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 500, 'title' => 'A']);
        $b = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 500, 'title' => 'B']);
        $this->buy($a, quantity: 3);
        $this->buy($b);
        // Unpaid orders do not count.
        app(OrderService::class)->createFromCart(User::factory()->create(), [$b->id => 5], 'USD', (string) Str::uuid());

        $this->artisan('shop:refresh-sales-ranking')->expectsOutputToContain('Updated the units sold of 2 products')->assertSuccessful();
        $this->assertSame(3, $a->fresh()->units_sold);
        $this->assertSame(1, $b->fresh()->units_sold);
        // Nothing changed: nothing is written.
        $this->artisan('shop:refresh-sales-ranking')->expectsOutputToContain('of 0 products')->assertSuccessful();

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $this->get('/products?sort=best')->assertOk()->assertSeeInOrder(['A', 'B']);
        $this->assertEmpty(array_filter($queries, fn ($sql) => str_contains($sql, 'from "products"') && str_contains($sql, 'order_items')),
            'The catalogue query must not aggregate order lines.');
    }

    // PERF-05: the catalogue's seller filter listed every seller on every page.
    public function test_perf05_seller_filter_lists_the_top_sellers_and_the_selected_one(): void
    {
        $sellers = collect(range(1, CatalogService::FILTER_SELLERS + 5))->map(function (int $i) {
            $seller = $this->seller();
            Product::factory()->create(['seller_id' => $seller->id, 'units_sold' => $i]);

            return $seller;
        });
        $html = $this->get('/products')->assertOk()->getContent();
        preg_match('/<select id="f-seller".*?<\/select>/s', $html, $select);
        $this->assertSame(CatalogService::FILTER_SELLERS + 1, substr_count($select[0], '<option'));
        $this->assertStringContainsString('Shop '.$sellers->last()->id.'<', $select[0]);
        $this->assertStringNotContainsString('Shop '.$sellers->first()->id.'<', $select[0]);

        // A seller outside the list is still offered when selected ("More from this seller").
        $this->get('/products?seller='.$sellers->first()->id)->assertOk()->assertSee('Products by Shop '.$sellers->first()->id)
            ->assertSee('<option value="'.$sellers->first()->id.'" selected>', false);
    }

    // PERF-03: reconciliation computes the earning check in SQL; the SQL must agree with earningFor().
    public function test_perf03_earning_sql_matches_earning_for(): void
    {
        mt_srand(7);
        $rows = [];
        foreach (range(1, 300) as $i) {
            $unit = $i <= 20 ? [1, 5, 15, 25, 35, 45, 105, 995, 1005, 1015, 4999, 5005, 10, 0, 99999999, 7, 12345, 50, 150, 250][$i - 1] : mt_rand(0, 2000000);
            $qty = mt_rand(1, 5);
            $discount = mt_rand(0, intdiv($unit * $qty, 3));
            $refunded = mt_rand(0, 3) === 0 ? mt_rand(0, $unit * $qty - $discount) : 0;
            $bps = [0, 1, 250, 500, 1000, 1250, 3333, 5000, 9999, 10000][mt_rand(0, 9)];
            $rows[] = [$unit, $qty, $discount, $refunded, $bps];
        }
        // Column types as in order_items: bigint amounts, integer quantity and rate.
        $values = implode(',', array_map(fn ($r) => "({$r[0]}::bigint, {$r[1]}::integer, {$r[2]}::bigint, {$r[3]}::bigint, {$r[4]}::integer)", $rows));
        $sql = 'SELECT '.PayoutService::EARNING_SQL.' AS earning FROM (VALUES '.$values.') AS order_items(unit_price_minor, quantity, discount_minor, refunded_minor, commission_bps)';
        $fromSql = array_map(fn ($r) => (int) $r->earning, DB::select($sql));
        $fromPhp = array_map(fn ($r) => PayoutService::earningFor($r[0] * $r[1] - $r[2] - $r[3], $r[4]), $rows);
        $this->assertSame($fromPhp, $fromSql);
        $this->assertSame(Money::applyBps(1005, 1000), 101);
    }

    public function test_perf03_reconciliation_reports_only_the_tampered_line(): void
    {
        $seller = $this->seller();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 1005]);
        $orders = collect(range(1, 3))->map(fn () => $this->buy($product));
        $this->assertSame([], app(PayoutService::class)->reconcile());

        $item = $orders[1]->items()->first();
        $item->forceFill(['seller_earning_minor' => 1])->save();
        $mismatches = array_values(array_filter(app(PayoutService::class)->reconcile(), fn ($m) => str_contains($m['check'], 'earning')));
        $this->assertSame([['seller_id' => $seller->id, 'currency' => 'USD', 'check' => "order_item {$item->id} earning",
            'expected' => PayoutService::earningFor(1005, $item->commission_bps), 'actual' => 1]], $mismatches);
    }

    // PERF-12: top lists per currency were cut to 50 rows across all currencies before filtering.
    public function test_perf12_top_lists_are_filtered_by_currency_in_sql(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $now = now();
        $insert = function (string $currency, int $price, int $n) use ($seller, $buyer, $now) {
            foreach (range(1, $n) as $i) {
                $product = Product::factory()->create(['seller_id' => $seller->id, 'currency' => $currency, 'price_minor' => $price]);
                $orderId = DB::table('orders')->insertGetId(['public_id' => (string) Str::uuid(), 'buyer_id' => $buyer->id, 'status' => 'delivered',
                    'subtotal_minor' => $price, 'total_minor' => $price, 'currency' => $currency, 'idempotency_key' => (string) Str::uuid(),
                    'created_at' => $now, 'updated_at' => $now, 'paid_at' => $now, 'delivered_at' => $now]);
                DB::table('order_items')->insert(['order_id' => $orderId, 'product_id' => $product->id, 'seller_id' => $seller->id, 'title' => "{$currency} {$i}",
                    'unit_price_minor' => $price, 'quantity' => 1, 'list_price_minor' => $price, 'list_currency' => $currency, 'fx_rate' => 1,
                    'commission_bps' => 1000, 'seller_earning_minor' => $price - Money::applyBps($price, 1000), 'created_at' => $now, 'updated_at' => $now]);
            }
        };
        $insert('USD', 9000, 55);
        $insert('EUR', 100, 2);

        $top = app(AnalyticsService::class)->topProducts(now()->subDay(), now()->addMinute(), 'EUR');
        $this->assertSame(['EUR', 'EUR'], $top->pluck('currency')->all());
        $this->assertSame(['EUR'], app(AnalyticsService::class)->topSellers(now()->subDay(), now()->addMinute(), 'EUR')->pluck('currency')->all());
    }

    // PERF-11 (payout side): several pending refunds in one reconciliation run.
    public function test_perf11_several_pending_refunds_are_polled_in_one_run(): void
    {
        Mail::fake();
        config(['services.shkeeper.payouts_enabled' => true, 'services.shkeeper.payout_username' => 'u', 'services.shkeeper.payout_password' => 'p',
            'shop.staff_alert_email' => 'ops@shop.test']);
        $product = $this->instantProductWithFile(['price_minor' => 500]);
        foreach (range(1, 2) as $i) {
            $order = $this->buy($product);
            $charge = $order->payments()->firstOrFail();
            Payment::create(['order_id' => $order->id, 'kind' => PaymentKind::Refund, 'parent_payment_id' => $charge->id, 'provider' => PaymentProvider::Shkeeper,
                'amount_minor' => 500, 'currency' => 'USD', 'status' => PaymentStatus::Pending, 'crypto' => 'BTC', 'raw_payload_json' => ['task_id' => 't'.$i]]);
        }
        Http::fake(['shkeeper.test/api/v1/BTC/payout/status*' => Http::response(['status' => 'error', 'message' => 'temporarily unavailable'], 503)]);
        $this->travel(25)->hours();

        app(ShkeeperPayoutService::class)->reconcile();
        $this->assertSame(2, Payment::query()->where('kind', 'refund')->where('status', 'pending')->count());
    }

    // PERF-14: with Shkeeper down, polling stops after a few failures instead of waiting out every timeout.
    public function test_perf14_payment_polling_stops_after_failures_in_a_row(): void
    {
        $product = Product::factory()->create(['price_minor' => 500]);
        foreach (range(1, 5) as $i) {
            $order = app(OrderService::class)->createFromCart(User::factory()->create(), [$product->id => 1], 'USD', (string) Str::uuid());
            Payment::create(['order_id' => $order->id, 'kind' => PaymentKind::Charge, 'provider' => PaymentProvider::Shkeeper, 'provider_reference' => 'inv-'.$i,
                'amount_minor' => 500, 'currency' => 'USD', 'status' => PaymentStatus::Pending, 'crypto' => 'BTC']);
        }
        Payment::query()->update(['created_at' => now()->subMinutes(10)]);
        Http::fake(['shkeeper.test/*' => Http::response('Service unavailable', 503)]);

        $this->artisan('shop:reconcile-payments')->assertSuccessful();
        Http::assertSentCount(PaymentService::POLL_FAILURES_BEFORE_STOP);
    }

    public function test_perf14_transfer_polling_stops_after_failures_in_a_row(): void
    {
        Mail::fake();
        config(['services.shkeeper.payouts_enabled' => true, 'services.shkeeper.payout_username' => 'u', 'services.shkeeper.payout_password' => 'p']);
        $product = $this->instantProductWithFile(['price_minor' => 500]);
        foreach (range(1, 5) as $i) {
            $order = $this->buy($product);
            Payment::create(['order_id' => $order->id, 'kind' => PaymentKind::Refund, 'parent_payment_id' => $order->payments()->firstOrFail()->id,
                'provider' => PaymentProvider::Shkeeper, 'amount_minor' => 500, 'currency' => 'USD', 'status' => PaymentStatus::Pending, 'crypto' => 'BTC',
                'raw_payload_json' => ['task_id' => 't'.$i]]);
        }
        Http::fake(['shkeeper.test/*' => Http::response('Service unavailable', 503)]);

        app(ShkeeperPayoutService::class)->reconcile();
        Http::assertSentCount(PaymentService::POLL_FAILURES_BEFORE_STOP);
    }

    // PERF-06: the sitemap is rendered once an hour, compressed, without a session.
    public function test_perf06_sitemap_is_cached_compressed_and_sessionless(): void
    {
        $product = Product::factory()->create(['title' => 'Mapped']);
        $plain = $this->get('/sitemap.xml')->assertOk()->assertHeader('Cache-Control', 'max-age=3600, public');
        $this->assertStringContainsString(route('products.show', $product->slug), $plain->getContent());
        $this->assertSame([], $plain->headers->getCookies());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $gzip = $this->get('/sitemap.xml', ['Accept-Encoding' => 'gzip, br'])->assertOk()->assertHeader('Content-Encoding', 'gzip');
        $this->assertEmpty(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'products')), 'The cached sitemap must not query products.');
        DB::disableQueryLog();
        $this->assertSame($plain->getContent(), gzdecode($gzip->getContent()));
    }

    // PERF-15: expired database cache entries (per-address rate-limit counters) are deleted.
    public function test_perf15_expired_cache_entries_are_pruned(): void
    {
        $now = now()->getTimestamp();
        DB::table('cache')->insert([
            ['key' => 'laravel-cache-search:203.0.113.7', 'value' => 'i:1;', 'expiration' => $now - 60],
            ['key' => 'laravel-cache-search:203.0.113.7:timer', 'value' => 'i:1;', 'expiration' => $now - 60],
            ['key' => 'laravel-cache-current', 'value' => 'i:1;', 'expiration' => $now + 600],
        ]);
        $this->artisan('shop:prune-cache')->expectsOutputToContain('Deleted 2 expired cache entries')->assertSuccessful();
        $this->assertContains('laravel-cache-current', DB::table('cache')->pluck('key')->all());
        $this->assertSame(0, DB::table('cache')->where('expiration', '<=', $now)->count());
    }

    // PERF-16: importing licence keys ran two queries per key while the product row was locked.
    public function test_perf16_licence_key_import_uses_a_fixed_number_of_queries(): void
    {
        $seller = $this->seller();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock' => null]);
        $this->actingAs($seller);
        $this->postForm(route('seller.products.keys.store', $product->id), ['keys' => implode("\n", array_map(fn ($i) => 'OLD-'.$i, range(1, 50)))])
            ->assertSessionHas('success');

        $keys = array_merge(array_map(fn ($i) => 'OLD-'.$i, range(1, 50)), array_map(fn ($i) => 'NEW-'.$i, range(1, 400)));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postForm(route('seller.products.keys.store', $product->id), ['keys' => implode("\n", $keys)])
            ->assertSessionHas('success', 'Added 400 licence keys; stock increased by 400. Skipped 50 duplicates.');
        $this->assertLessThan(25, count(DB::getQueryLog()));
        DB::disableQueryLog();

        $this->assertSame(450, $product->fresh()->stock);
        $this->assertSame(450, $product->licenseKeys()->count());
        $stored = $product->licenseKeys()->where('key_fingerprint', hash_hmac('sha256', 'NEW-7', (string) config('app.key')))->firstOrFail();
        $this->assertSame('NEW-7', $stored->key_encrypted);
        $this->assertNotSame('NEW-7', DB::table('product_license_keys')->where('id', $stored->id)->value('key_encrypted'));
    }

    // PERF-18: the admin dashboard's live version read eight figures with eight queries every 10 s.
    public function test_perf18_admin_dashboard_version_is_one_query_and_still_tracks_changes(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $order = $this->buy(Product::factory()->create(['price_minor' => 500]));
        $first = $this->getJson('/live?path=/admin')->json('version');
        $this->assertNotNull($first);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/live?path=/admin')->assertOk();
        $versionQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'max(updated_at)') || str_contains($q['query'], 'max("updated_at")'));
        DB::disableQueryLog();
        $this->assertCount(1, $versionQueries);

        $this->travel(2)->seconds();
        $order->touch();
        $second = $this->getJson('/live?path=/admin')->json('version');
        $this->assertNotSame($first, $second);
        DB::table('webhook_events')->insert(['provider' => 'shkeeper', 'event_key' => str_repeat('a', 64), 'payload' => '{}', 'status' => 'dead', 'attempts' => 5]);
        $this->assertNotSame($second, $this->getJson('/live?path=/admin')->json('version'));
    }
}
