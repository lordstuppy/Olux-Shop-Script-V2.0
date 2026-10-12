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
use App\Support\SelfDealing;
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

    /** Status polls that failed in a row during the current reconcile() run. */
    private int $pollFailures = 0;

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
        SelfDealing::assertNotSelf($admin, $payout->seller_id);
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
            if ($payment === null) {
                return 'ignored';
            }
            if ($payment->status === PaymentStatus::Failed && $status === 'SUCCESS') {
                // Recorded as failed, but the money went out: the refundable amount may
                // already have been refunded again. Never booked automatically.
                $this->alerts->send(__('Crypto refund #:id was sent after all', ['id' => $payment->id]), [
                    __('The shop recorded this refund as failed, but Shkeeper now reports it as sent (transaction :txid).', ['txid' => $txid ?? '-']),
                    __('Check whether the buyer was refunded again in the meantime, then correct the order.'),
                ], route('admin.orders.show', $payment->order), 'refund-late-success:'.$payment->id);

                return 'ignored';
            }
            if ($payment->status !== PaymentStatus::Pending) {
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
        $this->pollFailures = 0;
        $unknown = __('Shkeeper has no record of this transfer :minutes minutes after it was requested, so it was never sent.', ['minutes' => self::UNKNOWN_AFTER_MINUTES]);
        // The "never sent" rule only applies when the transfer request itself had
        // no answer (no task id). Once Shkeeper accepted it, only Shkeeper's own
        // FAIL fails it.
        foreach (Payout::query()->with('seller')->where('status', PayoutStatus::Processing->value)->where('provider', 'shkeeper')->limit(100)->get() as $payout) {
            $accepted = (string) $payout->provider_reference !== '';
            $updated += $this->poll($payout->crypto, $payout->externalId(), $accepted ? null : $payout->processed_at,
                fn () => $this->payoutFailed($payout, $unknown));
            $this->alertIfStuck('payout', $payout->id, $payout->processed_at, route('admin.payouts.index'));
            if ($this->pollFailures >= PaymentService::POLL_FAILURES_BEFORE_STOP) {
                return $updated;
            }
        }
        $pending = Payment::query()->with('order')->where('kind', PaymentKind::Refund->value)->where('provider', PaymentProvider::Shkeeper->value)
            ->where('status', PaymentStatus::Pending->value)->limit(100)->get();
        foreach ($pending as $payment) {
            $accepted = (string) ($payment->raw_payload_json['task_id'] ?? '') !== '';
            $updated += $this->poll((string) $payment->crypto, 'refund-'.$payment->id, $accepted ? null : $payment->created_at,
                fn () => $this->refunds->failPending($payment, $unknown));
            $this->alertIfStuck('refund', $payment->id, $payment->created_at, route('admin.orders.show', $payment->order));
            if ($this->pollFailures >= PaymentService::POLL_FAILURES_BEFORE_STOP) {
                break;
            }
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
            $this->pollFailures = 0;
        } catch (ShkeeperException $e) {
            Log::warning('Shkeeper payout status poll for {external_id} failed: {reason}', ['external_id' => $externalId, 'reason' => $e->getMessage()]);
            // reconcile() stops after a few failures in a row (Shkeeper down), as PaymentService::reconcilePending does.
            $this->pollFailures++;

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

    /** A transfer still unresolved after a day needs a person: Shkeeper may be unreachable or misconfigured. */
    private function alertIfStuck(string $kind, int $id, ?\DateTimeInterface $since, string $url): void
    {
        if ($since === null || $since > now()->subHours(24)) {
            return;
        }
        $this->alerts->send(__('Shkeeper :kind #:id unresolved for over a day', ['kind' => $kind, 'id' => $id]), [
            __('The shop cannot get a result for this transfer from Shkeeper. Check it in the Shkeeper admin.'),
            __('Do not pay or refund it another way until you know whether it was sent.'),
        ], $url, 'transfer-stuck:'.$kind.':'.$id, 24 * 60);
    }

    private function payoutSucceeded(Payout $payout, ?string $txid): string
    {
        $wasFailed = $payout->status === PayoutStatus::Failed;
        // Failed too: a failure the shop assumed (no answer) can turn out to be a sent transfer.
        $changed = $this->payouts->transitionAutomated($payout, PayoutStatus::Paid, [PayoutStatus::Processing, PayoutStatus::Failed], null, [
            'reference' => $txid ?: $payout->provider_reference,
        ]);
        if ($changed && $wasFailed) {
            $this->alerts->send(__('Payout #:id was sent after all', ['id' => $payout->id]), [
                __('It was recorded as failed, but Shkeeper reports it as sent (transaction :txid); it is now marked paid.', ['txid' => $txid ?? '-']),
                __('If the seller was paid another way in the meantime, recover the second payment.'),
            ], route('admin.payouts.index'), 'payout-late-success:'.$payout->id);
        }
        if (! $changed && $payout->fresh()?->status === PayoutStatus::Paid && $txid !== null && $payout->fresh()->reference !== $txid) {
            $this->alerts->send(__('Payout #:id: possible double payment', ['id' => $payout->id]), [
                __('The payout is marked paid with reference :reference, and Shkeeper also reports transaction :txid.', ['reference' => (string) $payout->fresh()->reference, 'txid' => $txid]),
            ], route('admin.payouts.index'), 'payout-double:'.$payout->id);
        }
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
