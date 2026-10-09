<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use App\Services\UserService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SellerApplicationController extends Controller
{
    public function show(Request $request): View
    {
        return view('seller.apply', [
            'profile' => $request->user()?->sellerProfile,
            'currencies' => Money::supported(),
            'cryptos' => app(PaymentService::class)->availableCryptos(),
        ]);
    }

    public function store(Request $request, UserService $users): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:80'],
            'payout_currency' => ['required', Rule::in(Money::supported())],
            'payout_crypto' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'payout_address' => ['required', 'string', 'min:10', 'max:255', 'regex:/^[A-Za-z0-9:_.-]+$/'],
            'about' => ['nullable', 'string', 'max:2000'],
            'accept_seller_terms' => ['accepted'],
        ], ['accept_seller_terms.accepted' => __('You must accept the seller terms, including the prohibited goods list.')]);

        $users->applyAsSeller($request->user(), $data);

        return redirect()->route('seller.apply')->with('success', __('Seller application submitted. An administrator will review it; you will see the decision on this page.'));
    }
}
