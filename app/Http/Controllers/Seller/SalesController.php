<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\DeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesController extends Controller
{
    public function index(Request $request): View
    {
        $items = OrderItem::query()->where('seller_id', $request->user()->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'delivered', 'partially_refunded', 'refunded']))
            ->with(['order', 'product'])->latest('id')->paginate(25);

        return view('seller.sales', ['items' => $items]);
    }

    public function deliver(Request $request, OrderItem $item, DeliveryService $delivery): RedirectResponse
    {
        abort_unless($item->seller_id === $request->user()->id, 403);
        $data = $request->validate(['payload' => ['required', 'string', 'max:10000']]);
        $delivery->deliverManually($item, $request->user(), $data['payload']);

        return back()->with('success', "Delivered \"{$item->title}\" for order {$item->order->shortId()}. The buyer was notified by email.");
    }
}
