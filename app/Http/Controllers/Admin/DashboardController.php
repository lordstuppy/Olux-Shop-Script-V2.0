<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Enums\ProductStatus;
use App\Enums\SellerProfileStatus;
use App\Enums\TicketStatus;
use App\Enums\WebhookEventStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $paidStates = ['paid', 'delivered', 'partially_refunded', 'refunded'];

        return view('admin.dashboard', [
            'counts' => [
                __('Users') => User::count(),
                __('Orders awaiting payment') => Order::where('status', OrderStatus::Pending->value)->count(),
                __('Paid orders awaiting delivery') => Order::where('status', OrderStatus::Paid->value)->count(),
                __('Products awaiting review') => Product::where('status', ProductStatus::PendingReview->value)->count(),
                __('Seller applications') => SellerProfile::where('status', SellerProfileStatus::Pending->value)->count(),
                __('Open tickets') => Ticket::where('status', TicketStatus::Open->value)->count(),
                __('Payout requests') => Payout::where('status', PayoutStatus::Requested->value)->count(),
                __('Failed webhook events') => WebhookEvent::whereIn('status', [WebhookEventStatus::Failed->value, WebhookEventStatus::Dead->value])->count(),
            ],
            'revenue' => DB::table('orders')->whereIn('status', $paidStates)->where('paid_at', '>=', now()->subDays(30))
                ->groupBy('currency')->selectRaw('currency, SUM(total_minor) AS gross, SUM(refunded_minor) AS refunded, COUNT(*) AS orders')->get(),
            'recentOrders' => Order::with('buyer')->latest('id')->limit(10)->get(),
        ]);
    }
}
