<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\PayoutService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PayoutController extends Controller
{
    public function index(): View
    {
        return view('admin.payouts', [
            'payouts' => Payout::with('seller')->orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->latest('id')->paginate(30),
        ]);
    }

    public function approve(Request $request, Payout $payout, PayoutService $payouts): RedirectResponse
    {
        $payouts->approve($payout, $request->user());

        return back()->with('success', "Payout #{$payout->id} approved.");
    }

    public function paid(Request $request, Payout $payout, PayoutService $payouts): RedirectResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:255']]);
        $payouts->markPaid($payout, $request->user(), $data['reference']);

        return back()->with('success', "Payout #{$payout->id} of ".Money::format($payout->amount_minor, $payout->currency).' marked paid.');
    }

    public function reject(Request $request, Payout $payout, PayoutService $payouts): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $payouts->reject($payout, $request->user(), $data['note']);

        return back()->with('success', "Payout #{$payout->id} rejected; the amount was returned to the seller balance.");
    }
}
