<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\PayoutService;
use App\Services\ShkeeperPayoutService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PayoutController extends Controller
{
    public function index(): View
    {
        return view('admin.payouts', [
            'payouts' => Payout::with('seller.sellerProfile')->orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'approved' THEN 1 WHEN 'failed' THEN 2 WHEN 'processing' THEN 3 ELSE 4 END")->latest('id')->paginate(30),
            'shkeeperEnabled' => ShkeeperPayoutService::enabled(),
        ]);
    }

    public function approve(Request $request, Payout $payout, PayoutService $payouts): RedirectResponse
    {
        $payouts->approve($payout, $request->user());

        return back()->with('success', __('Payout #:id approved.', ['id' => $payout->id]));
    }

    public function paid(Request $request, Payout $payout, PayoutService $payouts): RedirectResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:255']]);
        $payouts->markPaid($payout, $request->user(), $data['reference']);

        return back()->with('success', __('Payout #:id of :amount marked paid.', ['id' => $payout->id, 'amount' => Money::format($payout->amount_minor, $payout->currency)]));
    }

    public function send(Request $request, Payout $payout, ShkeeperPayoutService $shkeeper): RedirectResponse
    {
        $shkeeper->sendPayout($payout->load('seller.sellerProfile'), $request->user());

        return back()->with('success', __('Payout #:id sent to Shkeeper as :crypto_amount :crypto. It is marked paid when Shkeeper confirms the transfer.', ['id' => $payout->id, 'crypto_amount' => $payout->fresh()->crypto_amount, 'crypto' => $payout->fresh()->crypto]));
    }

    public function reject(Request $request, Payout $payout, PayoutService $payouts): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $payouts->reject($payout, $request->user(), $data['note']);

        return back()->with('success', __('Payout #:id rejected; the amount was returned to the seller balance.', ['id' => $payout->id]));
    }
}
