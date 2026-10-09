<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\RefundService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'q' => ['nullable', 'string', 'max:64'],
        ]);
        $query = Order::with('buyer')->latest('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $query->whereRaw('CAST(public_id AS TEXT) LIKE ?', [addcslashes(strtolower($data['q']), '%_\\').'%']);
        }

        return view('admin.orders.index', ['orders' => $query->paginate(30)->withQueryString(), 'filters' => $data]);
    }

    public function show(Order $order): View
    {
        $order->load(['buyer', 'items.seller', 'payments.parent', 'coupon', 'invoice']);

        return view('admin.orders.show', ['order' => $order]);
    }

    public function refund(Request $request, Order $order, RefundService $refunds): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:16'],
            'method' => ['required', Rule::in(['balance', 'manual'])],
            'reference' => ['nullable', 'string', 'max:128'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $amount = Money::parseInput($data['amount'], $order->currency);
        } catch (InvalidArgumentException) {
            throw new UserFacingException("Enter the refund amount as a number such as 5.00 in {$order->currency}.");
        }

        $refunds->refund($order, $amount, $data['method'], $request->user(), $data['reference'] ?? null, $data['reason'] ?? null);

        return back()->with('success', 'Refunded '.Money::format($amount, $order->currency)." on order {$order->shortId()} via {$data['method']}.");
    }
}
