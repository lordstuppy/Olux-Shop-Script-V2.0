<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\UserFacingException;
use App\Mail\RefundIssuedMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Full and partial refunds. Each refund is its own payments row (kind
 * "refund") linked to the original charge, so the charge row is never edited.
 *
 * Methods:
 *  - balance:  credited to the buyer's shop balance immediately.
 *  - manual:   paid back outside the shop; the transaction reference is recorded.
 *  - shkeeper: sent on-chain through Shkeeper's payout API (ShkeeperPayoutService).
 *              The refund row stays "pending" (and counts against the
 *              refundable amount) until Shkeeper confirms the transfer; only
 *              then are order totals and seller earnings adjusted.
 */
class RefundService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly BalanceService $balances,
        private readonly PayoutService $payouts,
        private readonly AuditLogger $audit,
    ) {}

    public function refund(Order $order, int $amountMinor, string $method, User $admin, ?string $reference = null, ?string $reason = null): Payment
    {
        if (! in_array($method, ['balance', 'manual'], true)) {
            throw new UserFacingException(__('Choose a refund method: balance or manual.'));
        }
        if ($method === 'manual' && ($reference === null || trim($reference) === '')) {
            throw new UserFacingException(__('A manual refund needs the transaction reference of the outgoing payment.'));
        }

        $payment = DB::transaction(function () use ($order, $amountMinor, $method, $admin, $reference, $reason) {
            [$locked, $charge] = $this->lockRefundable($order, $amountMinor);

            $payment = Payment::create([
                'order_id' => $locked->id,
                'kind' => PaymentKind::Refund,
                'parent_payment_id' => $charge->id,
                'provider' => $method === 'balance' ? PaymentProvider::Balance : PaymentProvider::Manual,
                'provider_reference' => $method === 'manual' ? trim((string) $reference) : null,
                'amount_minor' => $amountMinor,
                'received_minor' => 0,
                'currency' => $locked->currency,
                'status' => PaymentStatus::Confirmed,
                'raw_payload_json' => $reason ? ['reason' => $reason] : null,
                'created_by' => $admin->id,
                'confirmed_at' => now(),
            ]);

            if ($method === 'balance') {
                $this->balances->credit($locked->buyer, $amountMinor, $locked->currency, 'refund', $payment, "Refund for order {$locked->shortId()}");
            }
            $this->applyToOrder($locked, $payment, $admin, $method, $reason);

            return $payment;
        });

        $this->notifyBuyer($order, $payment);

        return $payment;
    }

    /**
     * Reserves a refund that will be sent through Shkeeper. Returns the
     * pending refund payment; ShkeeperPayoutService performs the transfer.
     */
    public function reservePending(Order $order, int $amountMinor, string $crypto, string $destination, User $admin, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($order, $amountMinor, $crypto, $destination, $admin, $reason) {
            [$locked, $charge] = $this->lockRefundable($order, $amountMinor);

            $payment = Payment::create([
                'order_id' => $locked->id,
                'kind' => PaymentKind::Refund,
                'parent_payment_id' => $charge->id,
                'provider' => PaymentProvider::Shkeeper,
                'amount_minor' => $amountMinor,
                'received_minor' => 0,
                'currency' => $locked->currency,
                'status' => PaymentStatus::Pending,
                'crypto' => $crypto,
                'wallet_address' => $destination,
                'raw_payload_json' => $reason ? ['reason' => $reason] : null,
                'created_by' => $admin->id,
            ]);
            $this->audit->log('refund.requested', $payment, [
                'order' => $locked->public_id,
                'amount' => Money::format($amountMinor, $locked->currency),
                'crypto' => $crypto,
            ], $admin);

            return $payment;
        });
    }

    /** Shkeeper confirmed the on-chain refund: book it. Safe to call twice. */
    public function confirmPending(Payment $payment, ?string $txid): void
    {
        $confirmed = DB::transaction(function () use ($payment, $txid) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PaymentStatus::Pending) {
                return false;
            }
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            $locked->status = PaymentStatus::Confirmed;
            $locked->confirmed_at = now();
            $locked->provider_reference = $txid !== null && $txid !== '' ? mb_substr($txid, 0, 128) : $locked->provider_reference;
            $locked->save();

            $actor = User::find($locked->created_by) ?? $order->buyer;
            $this->applyToOrder($order, $locked, $actor, 'shkeeper', $locked->raw_payload_json['reason'] ?? null);
            $payment->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($confirmed) {
            $this->notifyBuyer($payment->order, $payment);
        }
    }

    public function failPending(Payment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PaymentStatus::Pending) {
                return;
            }
            $locked->status = PaymentStatus::Failed;
            $locked->failure_reason = mb_substr($reason, 0, 500);
            $locked->save();
            $this->audit->log('refund.failed', $locked, ['reason' => $reason]);
            Log::error('Refund {payment_id} via Shkeeper failed: {reason}', ['payment_id' => $locked->id, 'reason' => $reason]);
        });
    }

    /**
     * Locks the order and checks that the amount is refundable, counting
     * refunds that are still in flight.
     *
     * @return array{0: Order, 1: Payment}
     */
    private function lockRefundable(Order $order, int $amountMinor): array
    {
        if ($amountMinor <= 0) {
            throw new UserFacingException(__('The refund amount must be greater than zero.'));
        }

        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        if (! $locked->status->isPaidState() || $locked->status === OrderStatus::Refunded) {
            throw new UserFacingException(__('Order :order is :status and cannot be refunded.', ['order' => $locked->shortId(), 'status' => mb_strtolower($locked->status->label())]));
        }

        $inFlight = (int) Payment::query()->where('order_id', $locked->id)->where('kind', PaymentKind::Refund->value)
            ->where('status', PaymentStatus::Pending->value)->sum('amount_minor');
        $refundable = $locked->refundableMinor() - $inFlight;
        if ($amountMinor > $refundable) {
            throw new UserFacingException(__(
                'Refund amount :amount exceeds the refundable :refundable for order :order.',
                ['amount' => Money::format($amountMinor, $locked->currency), 'refundable' => Money::format(max(0, $refundable), $locked->currency), 'order' => $locked->shortId()],
            ).($inFlight > 0 ? ' '.__(':amount is already being refunded.', ['amount' => Money::format($inFlight, $locked->currency)]) : ''));
        }

        $charge = Payment::query()->where('order_id', $locked->id)->where('kind', PaymentKind::Charge->value)
            ->where('status', PaymentStatus::Confirmed->value)->orderBy('id')->first();
        if ($charge === null) {
            throw new UserFacingException(__('Order :order has no confirmed charge to refund.', ['order' => $locked->shortId()]));
        }

        return [$locked, $charge];
    }

    /** Spreads the refund over the lines, adjusts seller earnings and the order status. */
    private function applyToOrder(Order $locked, Payment $payment, User $actor, string $method, ?string $reason): void
    {
        $amountMinor = $payment->amount_minor;
        $items = $locked->items()->orderBy('id')->lockForUpdate()->get();
        $weights = $items->mapWithKeys(fn ($i) => [$i->id => $i->netMinor() - $i->refunded_minor])->all();
        $shares = Money::allocate($amountMinor, $weights);
        foreach ($items as $item) {
            $share = $shares[$item->id] ?? 0;
            if ($share === 0) {
                continue;
            }
            $item->refunded_minor += $share;
            $item->save();
            $this->payouts->adjustForRefund($item, $locked->currency);
        }

        $locked->refunded_minor += $amountMinor;
        $next = $locked->refunded_minor === $locked->total_minor ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded;
        $this->orders->transition($locked, $next);

        $this->audit->log('refund.created', $payment, [
            'order' => $locked->public_id,
            'amount' => Money::format($amountMinor, $locked->currency),
            'method' => $method,
            'reason' => $reason,
        ], $actor);

        Log::info('Refund of {amount} recorded for order {public_id} via {method}', [
            'amount' => Money::format($amountMinor, $locked->currency),
            'public_id' => $locked->public_id,
            'method' => $method,
        ]);
    }

    private function notifyBuyer(Order $order, Payment $payment): void
    {
        $order = $order->fresh(['buyer']);
        Mail::to($order->buyer)->queue((new RefundIssuedMail($order, $payment))->afterCommit());
    }
}
