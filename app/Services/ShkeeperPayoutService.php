<?php

namespace App\Services;

use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Exceptions\ShkeeperException;
use App\Exceptions\UserFacingException;
use App\Mail\PayoutStatusMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Services\Shkeeper\ShkeeperClient;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends approved seller payouts and crypto refunds through Shkeeper's payout
 * API. Every transfer still needs a staff action (and a recent password);
 * nothing is sent automatically by a schedule.
 *
 * External ids: "payout-{id}" for seller payouts, "refund-{payment id}" for refunds.
 */
class ShkeeperPayoutService
{
    public function __construct(
        private readonly ShkeeperClient $client,
        private readonly PayoutService $payouts,
        private readonly RefundService $refunds,
        private readonly AuditLogger $audit,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('services.shkeeper.payouts_enabled');
    }

    public function sendPayout(Payout $payout, User $admin): void
    {
        $this->assertEnabled();
        if (! in_array($payout->status, [PayoutStatus::Approved, PayoutStatus::Failed], true)) {
            throw new UserFacingException("Payout #{$payout->id} must be approved before it can be sent.");
        }
        $profile = $payout->seller->sellerProfile;
        if ($until = $profile?->payoutsBlockedUntil()) {
            throw new UserFacingException('The seller changed the payout address recently; sending is possible after '.$until->format('Y-m-d H:i').' UTC.');
        }
        $crypto = (string) ($profile?->payout_crypto ?? '');
        if ($crypto === '') {
            throw new UserFacingException('The seller has not chosen a payout cryptocurrency. Pay this one manually or ask the seller to update the payout settings.');
        }

        try {
            $cryptoAmount = $this->client->quote($crypto, $payout->amount_minor, $payout->currency);
            $taskId = $this->client->createPayout($crypto, $cryptoAmount, $payout->destination, $this->fee($crypto), $payout->externalId(), $this->callbackUrl());
        } catch (ShkeeperException $e) {
            Log::error('Shkeeper payout {payout_id} failed to start: {reason}', ['payout_id' => $payout->id, 'reason' => $e->getMessage()]);
            throw new UserFacingException("Shkeeper did not accept payout #{$payout->id}: {$e->getMessage()}");
        }

        $this->payouts->transitionAutomated($payout, PayoutStatus::Processing, [PayoutStatus::Approved, PayoutStatus::Failed], $admin, [
            'provider' => 'shkeeper',
            'crypto' => $crypto,
            'crypto_amount' => $cryptoAmount,
            'provider_reference' => $taskId,
            'failure_reason' => null,
        ]);
    }

    public function sendRefund(Order $order, int $amountMinor, string $crypto, string $destination, User $admin, ?string $reason): Payment
    {
        $this->assertEnabled();
        if (! ShkeeperClient::isValidCryptoName($crypto) || trim($destination) === '') {
            throw new UserFacingException('A crypto refund needs a cryptocurrency and the buyer\'s destination address.');
        }

        $payment = $this->refunds->reservePending($order, $amountMinor, $crypto, trim($destination), $admin, $reason);
        try {
            $cryptoAmount = $this->client->quote($crypto, $amountMinor, $order->currency);
            $taskId = $this->client->createPayout($crypto, $cryptoAmount, trim($destination), $this->fee($crypto), 'refund-'.$payment->id, $this->callbackUrl());
        } catch (ShkeeperException $e) {
            $this->refunds->failPending($payment, $e->getMessage());
            throw new UserFacingException('Shkeeper did not accept the refund transfer: '.$e->getMessage().' Nothing was refunded.');
        }

        $payment->forceFill([
            'crypto_amount' => $cryptoAmount,
            'raw_payload_json' => array_merge($payment->raw_payload_json ?? [], ['task_id' => $taskId]),
        ])->save();

        return $payment;
    }

    /**
     * Applies a payout result from the signed callback or a status poll.
     * Statuses: SUCCESS, FAIL, IN_PROGRESS. Idempotent.
     */
    public function handleResult(string $externalId, string $status, ?string $txid): string
    {
        $status = strtoupper($status);
        if (preg_match('/^payout-(\d+)$/', $externalId, $m)) {
            $payout = Payout::with('seller')->find((int) $m[1]);
            if ($payout === null) {
                return 'ignored';
            }

            return match ($status) {
                'SUCCESS' => $this->payoutSucceeded($payout, $txid),
                'FAIL', 'FAILURE', 'FAILED' => $this->payoutFailed($payout, 'Shkeeper reported the transfer as failed.'),
                default => 'ignored',
            };
        }

        if (preg_match('/^refund-(\d+)$/', $externalId, $m)) {
            $payment = Payment::query()->whereKey((int) $m[1])->where('kind', PaymentKind::Refund->value)
                ->where('provider', PaymentProvider::Shkeeper->value)->first();
            if ($payment === null || $payment->status !== PaymentStatus::Pending) {
                return 'ignored';
            }
            if ($status === 'SUCCESS') {
                $this->refunds->confirmPending($payment, $txid);

                return 'processed';
            }
            if (in_array($status, ['FAIL', 'FAILURE', 'FAILED'], true)) {
                $this->refunds->failPending($payment, 'Shkeeper reported the transfer as failed.');

                return 'processed';
            }

            return 'ignored';
        }

        return 'ignored';
    }

    /** Polls Shkeeper for transfers whose callback has not arrived. */
    public function reconcile(): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $updated = 0;
        foreach (Payout::query()->where('status', PayoutStatus::Processing->value)->where('provider', 'shkeeper')->limit(100)->get() as $payout) {
            $updated += $this->poll($payout->crypto, $payout->externalId());
        }
        $pending = Payment::query()->where('kind', PaymentKind::Refund->value)->where('provider', PaymentProvider::Shkeeper->value)
            ->where('status', PaymentStatus::Pending->value)->limit(100)->get();
        foreach ($pending as $payment) {
            $updated += $this->poll((string) $payment->crypto, 'refund-'.$payment->id);
        }

        return $updated;
    }

    private function poll(?string $crypto, string $externalId): int
    {
        if ($crypto === null || $crypto === '') {
            return 0;
        }
        try {
            $result = $this->client->payoutStatus($crypto, $externalId);
        } catch (ShkeeperException $e) {
            Log::warning('Shkeeper payout status poll for {external_id} failed: {reason}', ['external_id' => $externalId, 'reason' => $e->getMessage()]);

            return 0;
        }
        if ($result === null) {
            return 0;
        }

        return $this->handleResult($externalId, $result['status'], $result['txid']) === 'processed' ? 1 : 0;
    }

    private function payoutSucceeded(Payout $payout, ?string $txid): string
    {
        $changed = $this->payouts->transitionAutomated($payout, PayoutStatus::Paid, [PayoutStatus::Processing], null, [
            'reference' => $txid ?: $payout->provider_reference,
        ]);
        if ($changed) {
            Log::info('Payout {payout_id} of {amount} confirmed by Shkeeper', ['payout_id' => $payout->id, 'amount' => Money::format($payout->amount_minor, $payout->currency)]);
            Mail::to($payout->seller)->queue(new PayoutStatusMail($payout->fresh()));
        }

        return $changed ? 'processed' : 'ignored';
    }

    private function payoutFailed(Payout $payout, string $reason): string
    {
        $changed = $this->payouts->transitionAutomated($payout, PayoutStatus::Failed, [PayoutStatus::Processing], null, ['failure_reason' => $reason]);
        if ($changed) {
            Log::error('Payout {payout_id} failed in Shkeeper', ['payout_id' => $payout->id]);
        }

        return $changed ? 'processed' : 'ignored';
    }

    private function fee(string $crypto): string
    {
        return (string) (config('services.shkeeper.payout_fees')[$crypto] ?? '0');
    }

    private function callbackUrl(): string
    {
        return config('services.shkeeper.payout_callback_url') ?: route('webhooks.shkeeper.payouts');
    }

    private function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new UserFacingException('Automatic payouts are turned off (SHKEEPER_PAYOUTS_ENABLED). Pay manually and record the reference.');
        }
    }
}
