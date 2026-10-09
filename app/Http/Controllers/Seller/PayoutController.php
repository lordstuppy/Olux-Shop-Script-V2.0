<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\PayoutService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class PayoutController extends Controller
{
    public function index(Request $request, PayoutService $payouts): View
    {
        $seller = $request->user();

        return view('seller.payouts', [
            'balances' => $payouts->balances($seller),
            'payouts' => Payout::query()->where('seller_id', $seller->id)->latest('id')->paginate(20),
            'profile' => $seller->sellerProfile,
            'holdDays' => (int) config('shop.payout_hold_days'),
        ]);
    }

    public function store(Request $request, PayoutService $payouts): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:16'],
            'currency' => ['required', Rule::in(Money::supported())],
        ]);
        try {
            $amount = Money::parseInput($data['amount'], $data['currency']);
        } catch (InvalidArgumentException) {
            throw new UserFacingException("Enter the payout amount as a number such as 50.00 in {$data['currency']}.");
        }

        $payout = $payouts->requestPayout($request->user(), $amount, $data['currency']);

        return back()->with('success', 'Payout #'.$payout->id.' of '.Money::format($amount, $data['currency']).' requested to '.$payout->destination.'.');
    }
}
