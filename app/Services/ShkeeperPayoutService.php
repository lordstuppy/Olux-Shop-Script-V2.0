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
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Services\Shkeeper\ShkeeperClient;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends approved seller payouts and crypto refunds through Shkeeper's payout
 * API. Every transfer still needs a staff action (and a recent password);
 * nothing is sent automatically by a schedule.
 *
 * External ids: "payout-{id}" (then "payout-{id}-a2", ... for later attempts)
 * for seller payouts, "refund-{payment id}" for refunds.
 */
class ShkeeperPayoutService
{
    /** A transfer Shkeeper still does not know this long after the request was never created. */
    private const UNKNOWN_AFTER_MINUTES = 30;

    public function __construct(
        private readonly ShkeeperClient $client,
        private readonly PayoutService $payouts,
        private readonly RefundService $refunds,
        private readonly AuditLogger $audit,
        private readonly StaffAlerts $alerts,
        private readonly Notifier $notifier,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('services.shkeeper.payouts_enabled');
    }

    public function sendPayout(Payout $payout, User $admin): void
    {
        $this->assertEnabled();
        if (! in_array($payout->status, [PayoutStatus::Approved, PayoutStatus::Failed], true)) {
            throw new UserFacingException(__('Payout #:id must be approved before it can be sent.', ['id' => $payout->id]));
        }
        $profile = $payout->seller->sellerProfile;
        if ($until = $profile?->payoutsBlockedUntil()) {
            throw new UserFacingException(__('The seller changed the payout address recently; sending is possible after :time UTC.', ['time' => $until->format('Y-m-d H:i')]));
        }
        $crypto = (string) ($profile?->payout_crypto ?? '');
        if ($crypto === '') {
            throw new UserFacingException(__('The seller has not chosen a payout cryptocurrency. Pay this one manually or ask the seller to update the payout settings.'));
        }

        try {
            $cryptoAmount = $this->client->quote($crypto, $payout->amount_minor, $payout->currency);
        } catch (ShkeeperException $e) {
            throw new UserFacingException(__('Shkeeper did not accept payout #:id: :reason', ['id' => $payout->id, 'reason' => $e->getMessage()]));
        }

        // Claim the payout before any money moves: a second click, a second
        // admin or a parallel "Reject" now finds it Processing and stops.
        $claimed = DB::transaction(function () use ($payout, $admin, $crypto, $cryptoAmount) {
            $locked = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [PayoutStatus::Approved, PayoutStatus::Failed], true)) {
                return false;
            }
            $locked->forceFill([
                'status' => PayoutStatus::Processing,
                'provider' => 'shkeeper',
                'crypto' => $crypto,
                'crypto_amount' => $cryptoAmount,
                'provider_reference' => null,
                'failure_reason' => null,
                'attempt' => $locked->attempt + 1,
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ])->save();
            $payout->setRawAttributes($locked->getAttributes(), true);
            $this->audit->log('payout.processing', $locked, ['provider' => 'shkeeper', 'crypto' => $crypto, 'crypto_amount' => $cryptoAmount, 'external_id' => $locked->externalId()], $admin);

            return true;
        });
        if (! $claimed) {
            throw new UserFacingException(__('Payout #:id was changed by someone else in the meantime. Reload the page.', ['id' => $payout->id]));
        }

        try {
            $taskId = $this->client->createPayout($crypto, $cryptoAmount, $payout->destination, $this->fee($crypto), $payout->externalId(), $this->callbackUrl());
        } catch (ShkeeperException $e) {
            Log::error('Shkeeper payout {payout_id} failed to start: {reason}', ['payout_id' => $payout->id, 'reason' => $e->getMessage()]);
            if ($e->outcomeUnknown) {
                // The transfer may be under way: keep it Processing so nobody sends or rejects it,
                // and let the status poll (shop:reconcile-payouts) settle it.
                $this->alerts->send(__('Payout #:id: Shkeeper did not answer', ['id' => $payout->id]), [
                    __('The transfer may or may not have started. Do not pay it manually.'),
                    __('The shop asks Shkeeper for its status every 5 minutes and updates the payout.'),
                ], route('admin.payouts.index'), 'payout-unknown:'.$payout->id);

                throw new UserFacingException(__('Shkeeper did not answer in time, so payout #:id may already be on its way. It stays "processing" until Shkeeper reports the result; do not pay it manually.', ['id' => $payout->id]));
            }
            $this->payouts->transitionAutomated($payout, PayoutStatus::Failed, [PayoutStatus::Processing], $admin, ['failure_reason' => mb_substr($e->getMessage(), 0, 500)]);

            throw new UserFacingException(__('Shkeeper did not accept payout #:id: :reason', ['id' => $payout->id, 'reason' => $e->getMessage()]));
        }

        $payout->forceFill(['provider_reference' => $taskId])->save();
    }

    public function sendRefund(Order $order, int $amountMinor, string $crypto, string $destination, User $admin, ?string $reason, ?OrderItem $item = null): Payment
    {
        $this->assertEnabled();
        if (! ShkeeperClient::isValidCryptoName($crypto) || trim($destination) === '') {
            throw new UserFacingException(__('A crypto refund needs a cryptocurrency and the buyer\'s destination address.'));
        }

        $payment = $this->refunds->reservePending($order, $amountMinor, $crypto, trim($destination), $admin, $reason, $item);
        try {
            $cryptoAmount = $this->client->quote($crypto, $amountMinor, $order->currency);
            $taskId = $this->client->createPayout($crypto, $cryptoAmount, trim($destination), $this->fee($crypto), 'refund-'.$payment->id, $this->callbackUrl());
        } catch (ShkeeperException $e) {
            if ($e->outcomeUnknown) {
                // Possibly sent: the refund stays pending (and counts against the refundable
                // amount) until the status poll reports SUCCESS or FAIL.
                Log::error('Shkeeper refund {payment_id} outcome unknown: {reason}', ['payment_id' => $payment->id, 'reason' => $e->getMessage()]);

                throw new UserFacingException(__('Shkeeper did not answer in time, so the refund may already be on its way. It stays pending until Shkeeper reports the result; do not refund this again.'));
            }
            $this->refunds->failPending($payment, $e->getMessage());
            throw new UserFacingException(__('Shkeeper did not accept the refund transfer: :reason Nothing was refunded.', ['reason' => $e->getMessage()]));
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
        if (preg_match('/^payout-(\d+)(?:-a\d+)?$/', $externalId, $m)) {
            $payout = Payout::with('seller')->find((int) $m[1]);
            if ($payout === null) {
                return 'ignored';
            }
            if ($externalId !== $payout->externalId()) {
                return $this->staleAttempt($payout, $externalId, $status, $txid);
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
        $unknown = __('Shkeeper has no record of this transfer :minutes minutes after it was requested, so it was never sent.', ['minutes' => self::UNKNOWN_AFTER_MINUTES]);
        foreach (Payout::query()->with('seller')->where('status', PayoutStatus::Processing->value)->where('provider', 'shkeeper')->limit(100)->get() as $payout) {
            $updated += $this->poll($payout->crypto, $payout->externalId(), $payout->processed_at,
                fn () => $this->payoutFailed($payout, $unknown));
        }
        $pending = Payment::query()->where('kind', PaymentKind::Refund->value)->where('provider', PaymentProvider::Shkeeper->value)
            ->where('status', PaymentStatus::Pending->value)->limit(100)->get();
        foreach ($pending as $payment) {
            $updated += $this->poll((string) $payment->crypto, 'refund-'.$payment->id, $payment->created_at,
                fn () => $this->refunds->failPending($payment, $unknown));
        }

        return $updated;
    }

    /**
     * A result for an earlier attempt than the one in flight. It never changes
     * the payout, but a SUCCESS means that earlier transfer did go out after
     * all, so the seller may now be paid twice: staff must check.
     */
    private function staleAttempt(Payout $payout, string $externalId, string $status, ?string $txid): string
    {
        Log::warning('Shkeeper result {status} for earlier attempt {external_id} of payout {payout_id} ignored', ['status' => $status, 'external_id' => $externalId, 'payout_id' => $payout->id]);
        if ($status === 'SUCCESS') {
            $this->alerts->send(__('Payout #:id: an earlier transfer attempt succeeded', ['id' => $payout->id]), [
                __('Shkeeper reports :external as sent (transaction :txid), but the payout was sent again since.', ['external' => $externalId, 'txid' => $txid ?? '-']),
                __('Check both transfers in Shkeeper; the seller may have been paid twice.'),
            ], route('admin.payouts.index'), 'payout-stale:'.$externalId);
        }

        return 'ignored';
    }

    /**
     * @param  callable(): mixed  $neverArrived  called when Shkeeper still does not know the
     *                                           transfer long after it was requested (the request
     *                                           that timed out never reached it)
     */
    private function poll(?string $crypto, string $externalId, ?\DateTimeInterface $requestedAt, callable $neverArrived): int
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
            if ($requestedAt !== null && $requestedAt < now()->subMinutes(self::UNKNOWN_AFTER_MINUTES)) {
                $neverArrived();

                return 1;
            }

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
            $this->notifier->send($payout->seller, new PayoutStatusMail($payout->fresh()));
        }

        return $changed ? 'processed' : 'ignored';
    }

    private function payoutFailed(Payout $payout, string $reason): string
    {
        $changed = $this->payouts->transitionAutomated($payout, PayoutStatus::Failed, [PayoutStatus::Processing], null, ['failure_reason' => $reason]);
        if ($changed) {
            Log::error('Payout {payout_id} failed in Shkeeper', ['payout_id' => $payout->id]);
            $this->alerts->send(__('Payout #:id failed in Shkeeper', ['id' => $payout->id]), [
                __('Amount: :amount', ['amount' => Money::format($payout->amount_minor, $payout->currency)]),
                __('Reason: :reason', ['reason' => mb_substr($reason, 0, 300)]),
                __('The seller has not been paid. Retry it or pay it manually.'),
            ], route('admin.payouts.index'));
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
            throw new UserFacingException(__('Automatic payouts are turned off (SHKEEPER_PAYOUTS_ENABLED). Pay manually and record the reference.'));
        }
    }
}
