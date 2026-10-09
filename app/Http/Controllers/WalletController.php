<?php

namespace App\Http\Controllers;

use App\Services\GiftCardService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account.wallet', [
            'user' => $user,
            'transactions' => $user->balanceTransactions()->latest('id')->paginate(20),
        ]);
    }

    public function redeem(Request $request, GiftCardService $giftCards): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $card = $giftCards->redeem($request->user(), $data['code']);

        return redirect()->route('wallet.show')->with('success', 'Gift card redeemed: '.Money::format($card->amount_minor, $card->currency).' added to your balance.');
    }
}
