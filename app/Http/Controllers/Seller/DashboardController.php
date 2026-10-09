<?php

namespace App\Http\Controllers\Seller;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\PayoutService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PayoutService $payouts): View
    {
        $seller = $request->user();

        $awaitingDelivery = OrderItem::query()->where('seller_id', $seller->id)->whereNull('delivered_at')
            ->whereHas('order', fn ($q) => $q->whereIn('status', [OrderStatus::Paid->value]))
            ->whereHas('product', fn ($q) => $q->where('delivery_type', DeliveryType::Manual->value))
            ->with('order')->orderBy('id')->limit(20)->get();

        return view('seller.dashboard', [
            'balances' => $payouts->balances($seller),
            'productCounts' => $seller->products()->groupBy('status')->selectRaw('status, COUNT(*) AS c')->pluck('c', 'status'),
            'awaitingDelivery' => $awaitingDelivery,
            'recentSales' => OrderItem::query()->where('seller_id', $seller->id)->whereNotNull('seller_earning_minor')
                ->whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'delivered', 'partially_refunded', 'refunded']))
                ->with('order')->latest('id')->limit(10)->get(),
        ]);
    }
}
