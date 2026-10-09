<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\UserFacingException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Full and partial refunds. Each refund is its own payments row (kind
 * "refund") linked to the original charge, so the charge row is never edited.
 *
 * Methods:
 *  - balance: credited to the buyer's shop balance immediately.
 *  - manual:  paid back outside the shop (for example an on-chain transfer by
 *             staff); the transaction reference is recorded.
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
            throw new UserFacingException('Choose a refund method: balance or manual.');
        }
        if ($method === 'manual' && ($reference === null || trim($reference) === '')) {
            throw new UserFacingException('A manual refund needs the transaction reference of the outgoing payment.');
        }
        if ($amountMinor <= 0) {
            throw new UserFacingException('The refund amount must be greater than zero.');
        }

        $payment = DB::transaction(function () use ($order, $amountMinor, $method, $admin, $reference, $reason) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->isPaidState() || $locked->status === OrderStatus::Refunded) {
                throw new UserFacingException("Order {$locked->shortId()} is {$locked->status->label()} and cannot be refunded.");
            }
            $refundable = $locked->refundableMinor();
            if ($amountMinor > $refundable) {
                throw new UserFacingException(sprintf(
                    'Refund amount %s exceeds the refundable %s for order %s.',
                    Money::format($amountMinor, $locked->currency),
                    Money::format($refundable, $locked->currency),
                    $locked->shortId(),
                ));
            }

            $charge = Payment::query()->where('order_id', $locked->id)->where('kind', PaymentKind::Charge->value)
                ->where('status', PaymentStatus::Confirmed->value)->orderBy('id')->first();
            if ($charge === null) {
                throw new UserFacingException("Order {$locked->shortId()} has no confirmed charge to refund.");
            }

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
                'failure_reason' => null,
                'raw_payload_json' => $reason ? ['reason' => $reason] : null,
                'created_by' => $admin->id,
                'confirmed_at' => now(),
            ]);

            if ($method === 'balance') {
                $this->balances->credit($locked->buyer, $amountMinor, $locked->currency, 'refund', $payment, "Refund for order {$locked->shortId()}");
            }

            // Spread the refund over the lines in proportion to what is still refundable on each.
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
            ], $admin);

            return $payment;
        });

        Log::info('Refund of {amount} recorded for order {public_id} via {method}', [
            'amount' => Money::format($amountMinor, $order->currency),
            'public_id' => $order->public_id,
            'method' => $method,
        ]);

        return $payment;
    }
}
