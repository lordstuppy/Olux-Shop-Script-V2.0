<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\AnalyticsService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Support\GatewayLog;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsDashboardTest extends TestCase
{
    private function sell(Product $product, int $qty = 1): Order
    {
        $buyer = User::factory()->withBalance(100000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => $qty], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);

        return $order->fresh();
    }

    private function seedSales(): array
    {
        config(['shop.commission_bps' => 1000]);
        $first = User::factory()->seller()->create();
        $second = User::factory()->seller()->create();
        $icons = $this->instantProductWithFile(['seller_id' => $first->id, 'price_minor' => 2000, 'title' => 'Icon pack']);
        $fonts = $this->instantProductWithFile(['seller_id' => $second->id, 'price_minor' => 5000, 'title' => 'Font bundle']);

        // Previous 30-day period: one 20.00 sale.
        $this->travel(-40)->days();
        $this->sell($icons);
        $this->travelBack();

        // Current period: 2 x 20.00 and 1 x 50.00, then 10.00 of the font sale refunded.
        $this->sell($icons, 2);
        $fontOrder = $this->sell($fonts);
        app(RefundService::class)->refund($fontOrder, 1000, 'balance', User::factory()->admin()->create());
        // An unpaid order counts against conversion.
        app(OrderService::class)->createFromCart(User::factory()->create(), [$icons->id => 1], 'USD', (string) Str::uuid());

        return [$icons, $fonts];
    }

    public function test_kpis_compare_with_the_previous_period(): void
    {
        $this->seedSales();
        $kpis = app(AnalyticsService::class)->kpis(now()->subDays(30), now(), 'USD');

        $this->assertSame(['value' => 8000, 'previous' => 2000], $kpis['net_revenue']);
        // 10% of the net line amounts: 4.00 on the icons, 4.00 on the fonts after the refund.
        $this->assertSame(['value' => 800, 'previous' => 200], $kpis['commission']);
        $this->assertSame(['value' => 2, 'previous' => 1], $kpis['orders']);
        $this->assertSame(4000, $kpis['average_order']['value']);
        $this->assertSame(['value' => 2, 'previous' => 1], $kpis['active_sellers']);
        $this->assertEqualsWithDelta(66.7, $kpis['conversion']['value'], 0.01);
    }

    public function test_dashboard_shows_trends_rankings_and_gateway_health(): void
    {
        [$icons] = $this->seedSales();
        WebhookEvent::create(['provider' => 'shkeeper', 'event_key' => str_repeat('a', 64), 'payload' => [], 'status' => 'dead']);
        GatewayLog::record('webhook', 'rejected_signature', ['ip' => '203.0.113.5']);

        $this->actingAs(User::factory()->admin()->create());
        $response = $this->get('/admin')->assertOk();
        $response->assertSee('Net revenue')->assertSee('80.00 USD')->assertSee('up 300% from 20.00 USD')
            ->assertSee('Platform commission')->assertSee('8.00 USD')
            ->assertSee('Revenue trend')->assertSee('Order volume')->assertSee('dash-revenue-title', false)->assertSee('dash-orders-title', false)
            ->assertSee('Top products')->assertSee('Font bundle')->assertSee('Icon pack')
            ->assertSee('Payment gateway health')->assertSee('Problem')->assertSee('Rejected signatures (24 h)');

        $this->get('/admin?days=7&currency=EUR')->assertOk()->assertSee('Last 7 days in EUR')->assertSee('No sales in this period.');
        $this->get('/admin?days=11')->assertSessionHasErrors('days');
    }

    public function test_content_roles_see_work_queues_but_not_financials(): void
    {
        $this->seedSales();
        foreach ([UserRole::Moderator, UserRole::Support] as $role) {
            $this->actingAs(User::factory()->staff($role)->create());
            $this->get('/admin')->assertOk()->assertSee('Needs attention')->assertSee('Open disputes')->assertDontSee('Net revenue')->assertDontSee('Platform commission');
        }
        $this->actingAs(User::factory()->staff(UserRole::Manager)->create());
        $this->get('/admin')->assertSee('Net revenue');
    }
}
