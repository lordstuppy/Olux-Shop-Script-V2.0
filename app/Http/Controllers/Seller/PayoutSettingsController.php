<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Mail\PayoutAddressChangedMail;
use App\Mail\PayoutAddressChangeMail;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Payout address changes need: a recent password, a confirmation link sent to
 * the account email, and a cool-down before the next payout. A stolen session
 * alone cannot redirect a seller's earnings.
 */
class PayoutSettingsController extends Controller
{
    public function show(Request $request, PaymentService $payments): View
    {
        $profile = $request->user()->sellerProfile;

        return view('seller.payout-settings', [
            'profile' => $profile,
            'cryptos' => $payments->availableCryptos(),
            'cooldownHours' => (int) config('shop.payout_address_cooldown_hours'),
        ]);
    }

    public function requestChange(Request $request, PaymentService $payments, AuditLogger $audit): RedirectResponse
    {
        $allowed = array_column($payments->availableCryptos(), 'name');
        $data = $request->validate([
            'payout_crypto' => ['required', 'string', 'in:'.implode(',', $allowed)],
            'payout_address' => ['required', 'string', 'min:10', 'max:255', 'regex:/^[A-Za-z0-9:_.-]+$/'],
        ], ['payout_address.regex' => 'The address may contain only letters, digits and : _ . -']);

        $user = $request->user();
        $profile = $user->sellerProfile;
        $token = Str::random(48);
        $profile->forceFill([
            'pending_payout_address' => $data['payout_address'],
            'pending_payout_crypto' => $data['payout_crypto'],
            'payout_change_token_hash' => hash('sha256', $token),
            'payout_change_expires_at' => now()->addDay(),
        ])->save();
        Mail::to($user)->queue(new PayoutAddressChangeMail($profile, route('seller.payout-address.confirm', $token)));
        $audit->log('seller.payout_address_change_requested', $profile, ['crypto' => $data['payout_crypto']], $user);

        return back()->with('success', "We emailed a confirmation link to {$user->email}. The new address takes effect after you confirm it (valid for 24 hours).");
    }

    public function showConfirm(Request $request, string $token): View
    {
        $profile = $this->profileForToken($request, $token);

        return view('seller.payout-address-confirm', ['profile' => $profile, 'token' => $token]);
    }

    public function confirm(Request $request, string $token, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        DB::transaction(function () use ($request, $token, $user, $audit) {
            $profile = $this->profileForToken($request, $token);
            $old = $profile->payout_address;
            $profile->forceFill([
                'payout_address' => $profile->pending_payout_address,
                'payout_crypto' => $profile->pending_payout_crypto,
                'payout_address_changed_at' => now(),
                'pending_payout_address' => null,
                'pending_payout_crypto' => null,
                'payout_change_token_hash' => null,
                'payout_change_expires_at' => null,
            ])->save();
            $audit->log('seller.payout_address_changed', $profile, ['from' => $old, 'to' => $profile->payout_address], $user);
            Mail::to($user)->queue((new PayoutAddressChangedMail($profile))->afterCommit());
        });

        return redirect()->route('seller.payout-settings')->with('success', 'Payout address updated. Payouts resume after '.config('shop.payout_address_cooldown_hours').' hours.');
    }

    private function profileForToken(Request $request, string $token)
    {
        $profile = $request->user()->sellerProfile;
        if ($profile === null || $profile->payout_change_token_hash === null
            || ! hash_equals($profile->payout_change_token_hash, hash('sha256', $token))
            || $profile->payout_change_expires_at === null || $profile->payout_change_expires_at->isPast()) {
            throw new UserFacingException('This confirmation link is invalid or has expired. Request the change again from your payout settings.');
        }

        return $profile;
    }
}
