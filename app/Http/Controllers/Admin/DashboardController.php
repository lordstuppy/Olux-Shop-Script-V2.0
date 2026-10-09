<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Enums\ProductStatus;
use App\Enums\SellerProfileStatus;
use App\Enums\TicketStatus;
use App\Enums\WebhookEventStatus;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\AnalyticsService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Staff landing page: work waiting for someone, and (for roles with
 * analytics.view) revenue, orders, products, sellers and gateway health.
 */
class DashboardController extends Controller
{
    public const PERIODS = [7, 30, 90];

    public function __invoke(Request $request, AnalyticsService $analytics): View
    {
        $data = $request->validate([
            'days' => ['nullable', Rule::in(self::PERIODS)],
            'currency' => ['nullable', Rule::in(Money::supported())],
        ]);
        $days = (int) ($data['days'] ?? 30);
        $currency = $data['currency'] ?? (string) config('shop.default_currency');
        // End of today, so rounding of timestamp(0) columns cannot hide the latest orders.
        $to = now()->endOfDay();
        $from = now()->subDays($days);

        $view = [
            'attention' => $this->attention($request),
            'recentOrders' => Order::with('buyer')->latest('id')->limit(10)->get(),
            'days' => $days,
            'currency' => $currency,
        ];

        if ($request->user()->can('analytics.view')) {
            $view += [
                'kpis' => $analytics->kpis($from, $to, $currency),
                'revenueSeries' => $analytics->dailyRevenue($from->copy()->startOfDay(), $to, $currency),
                'orderSeries' => $analytics->dailyOrders($from->copy()->startOfDay(), $to),
                'topProducts' => $analytics->topProducts($from, $to, $currency),
                'topSellers' => $analytics->topSellers($from, $to, $currency),
                'approvedSellers' => $analytics->approvedSellers(),
                'gateway' => $analytics->gatewayHealth(),
            ];
        }

        return view('admin.dashboard', $view);
    }

    /** @return list<array{label: string, value: int, route: ?string}> work queues, linked when the user may open them */
    private function attention(Request $request): array
    {
        $user = $request->user();
        $link = fn (string $ability, string $route, array $params = []) => $user->can($ability) ? route($route, $params) : null;

        return [
            ['label' => __('Open disputes'), 'value' => Dispute::whereIn('status', DisputeStatus::openValues())->count(), 'route' => $link('disputes.manage', 'admin.disputes.index')],
            ['label' => __('Paid orders awaiting delivery'), 'value' => Order::where('status', OrderStatus::Paid->value)->count(), 'route' => $link('orders.view', 'admin.orders.index', ['status' => 'paid'])],
            ['label' => __('Products awaiting review'), 'value' => Product::where('status', ProductStatus::PendingReview->value)->count(), 'route' => $link('products.manage', 'admin.products.index', ['status' => 'pending_review'])],
            ['label' => __('Seller applications'), 'value' => SellerProfile::where('status', SellerProfileStatus::Pending->value)->count(), 'route' => $link('sellers.manage', 'admin.sellers.index')],
            ['label' => __('Open tickets'), 'value' => Ticket::where('status', TicketStatus::Open->value)->count(), 'route' => $link('tickets.manage', 'admin.tickets.index')],
            ['label' => __('Payout requests'), 'value' => Payout::where('status', PayoutStatus::Requested->value)->count(), 'route' => $link('payouts.manage', 'admin.payouts.index')],
            ['label' => __('Failed webhook events'), 'value' => WebhookEvent::whereIn('status', [WebhookEventStatus::Failed->value, WebhookEventStatus::Dead->value])->count(), 'route' => $link('webhooks.manage', 'admin.webhooks.index')],
            ['label' => __('Orders awaiting payment'), 'value' => Order::where('status', OrderStatus::Pending->value)->count(), 'route' => $link('orders.view', 'admin.orders.index', ['status' => 'pending'])],
            ['label' => __('Users'), 'value' => User::count(), 'route' => $link('users.view', 'admin.users.index')],
        ];
    }
}
