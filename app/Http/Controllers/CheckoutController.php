<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Models\Order;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    private const COUPON_KEY = 'checkout.coupon';

    public function show(Request $request, CartService $cart, CouponService $coupons, PaymentService $payments): View|RedirectResponse
    {
        if ($cart->isEmpty()) {
            return redirect()->route('cart.show')->with('error', 'Your cart is empty. Add a product before checking out.');
        }

        $totals = $cart->totals();
        $coupon = null;
        $discount = 0;
        $couponError = null;
        if ($code = $request->session()->get(self::COUPON_KEY)) {
            try {
                [$coupon, $discount] = $coupons->preview($code, $totals['currency'], $totals['subtotal_minor']);
            } catch (UserFacingException $e) {
                $couponError = $e->getMessage();
                $request->session()->forget(self::COUPON_KEY);
            }
        }

        $user = $request->user();

        return view('checkout.show', [
            'totals' => $totals,
            'coupon' => $coupon,
            'discountMinor' => $discount,
            'totalMinor' => $totals['subtotal_minor'] - $discount,
            'couponError' => $couponError,
            'cryptos' => $payments->availableCryptos(),
            'balanceUsable' => $user->currency === $totals['currency'] && $user->balance_minor >= $totals['subtotal_minor'] - $discount,
            // A fresh key per rendered form; resubmitting the same form cannot create a second order.
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function applyCoupon(Request $request, CartService $cart, CouponService $coupons): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $totals = $cart->totals();
        [$coupon, $discount] = $coupons->preview($data['code'], $totals['currency'], $totals['subtotal_minor']);
        $request->session()->put(self::COUPON_KEY, $coupon->code);

        return redirect()->route('checkout.show')
            ->with('success', "Coupon {$coupon->code} applied: ".Money::format($discount, $totals['currency']).' off.');
    }

    public function removeCoupon(Request $request): RedirectResponse
    {
        $request->session()->forget(self::COUPON_KEY);

        return redirect()->route('checkout.show')->with('success', 'Coupon removed.');
    }

    public function store(Request $request, CartService $cart, OrderService $orders, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:64'],
            'payment_method' => ['required', 'in:crypto,balance'],
            'crypto' => ['required_if:payment_method,crypto', 'nullable', 'string', 'max:32'],
            'accept_terms' => ['accepted'],
        ], [
            'accept_terms.accepted' => 'You must accept the terms of service to place an order.',
            'crypto.required_if' => 'Choose the cryptocurrency you want to pay with.',
        ]);

        $user = $request->user();
        $order = $orders->createFromCart(
            $user,
            $cart->rawItems(),
            $cart->currency(),
            $data['idempotency_key'],
            $request->session()->get(self::COUPON_KEY),
        );
        $cart->clear();
        $request->session()->forget(self::COUPON_KEY);

        return $this->pay($order, $data['payment_method'], $data['crypto'] ?? null, $payments, $request);
    }

    private function pay(Order $order, string $method, ?string $crypto, PaymentService $payments, Request $request): RedirectResponse
    {
        if ($order->status->isPaidState()) {
            return redirect()->route('orders.result', $order);
        }

        try {
            if ($method === 'balance' || $order->total_minor === 0) {
                $payments->payWithBalance($order, $request->user());

                return redirect()->route('orders.result', $order);
            }

            $payments->startShkeeperPayment($order, (string) $crypto);
        } catch (UserFacingException $e) {
            // The order exists; send the buyer to it so the payment can be retried.
            return redirect()->route('orders.pay', $order)->with('error', $e->getMessage());
        }

        return redirect()->route('orders.pay', $order);
    }
}
