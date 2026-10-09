<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Services\GiftCardService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class GiftCardController extends Controller
{
    public function index(): View
    {
        return view('admin.gift-cards', ['cards' => GiftCard::with('redeemer')->latest('id')->paginate(30), 'currencies' => Money::supported()]);
    }

    public function store(Request $request, GiftCardService $giftCards): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:16'],
            'currency' => ['required', Rule::in(Money::supported())],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        try {
            $amount = Money::parseInput($data['amount'], $data['currency']);
        } catch (InvalidArgumentException) {
            throw new UserFacingException("Enter the amount as a number such as 25.00 in {$data['currency']}.");
        }

        [$card, $code] = $giftCards->create($amount, $data['currency'], $request->user(), isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null);

        // The plain code is shown exactly once; only its hash is stored.
        return back()->with('success', 'Gift card created for '.Money::format($amount, $data['currency']).'. Code: '.$code.' (copy it now; it will not be shown again).');
    }
}
