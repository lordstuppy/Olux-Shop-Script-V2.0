<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\ShkeeperException;
use App\Exceptions\UserFacingException;
use App\Jobs\DeliverOrder;
use App\Jobs\GenerateInvoicePdf;
use App\Mail\OrderPlacedMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Shkeeper\ShkeeperClient;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Orchestrates payments. The amount charged always comes from the order row;
 * nothing the browser sends is trusted. An order becomes paid only from a
 * verified Shkeeper notification (webhook or server-side status poll) or a
 * balance debit, never from a client redirect.
 */
class PaymentService
{
    public function __construct(
        private readonly ShkeeperClient $shkeeper,
        private readonly OrderService $orders,
        private readonly BalanceService $balances,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array{name: string, display_name: string}>
     */
    public function availableCryptos(): array
    {
        return Cache::remember('shkeeper.cryptos', now()->addMinutes(10), function () {
            try {
                $list = $this->shkeeper->availableCryptos();
                if ($list !== []) {
                    return $list;
                }
            } catch (ShkeeperException $e) {
                Log::warning('ShkeeperClient: crypto list unavailable, using fallback list: {reason}', ['reason' => $e->getMessage()]);
            }

            return array_map(fn ($c) => ['name' => $c, 'display_name' => $c], config('services.shkeeper.fallback_cryptos'));
        });
    }

    /**
     * Cryptos buyers can pay with: those Shkeeper offers minus the ones staff
     * switched off on /admin/gateway. Empty when crypto payments are off.
     *
     * @return list<array{name: string, display_name: string}>
     */
    public function paymentCryptos(): array
    {
        if (! config('shop.payments_crypto_enabled')) {
            return [];
        }
        $disabled = array_filter(array_map('trim', explode(',', strtoupper((string) config('shop.crypto_disabled')))));

        return array_values(array_filter($this->availableCryptos(), fn ($c) => ! in_array(strtoupper($c['name']), $disabled, true)));
    }

    public function startShkeeperPayment(Order $order, string $crypto): Payment
    {
        if ($order->status !== OrderStatus::Pending) {
            throw new UserFacingException(__('Order :order is :status; no payment is needed.', ['order' => $order->shortId(), 'status' => mb_strtolower($order->status->label())]));
        }
        if (! config('shop.payments_crypto_enabled')) {
            throw new UserFacingException(__('Crypto payments are switched off right now. Pay with your shop balance or try again later.'));
        }
        $allowed = array_column($this->paymentCryptos(), 'name');
        if (! in_array($crypto, $allowed, true)) {
            throw new UserFacingException(__(':crypto is not accepted. Choose one of: :options.', ['crypto' => $crypto, 'options' => implode(', ', $allowed)]));
        }

        // The HTTP call happens outside any transaction so no row lock is held while waiting.
        try {
            $invoice = $this->shkeeper->createInvoice($crypto, $order->public_id, $order->total_minor, $order->currency, config('services.shkeeper.callback_url') ?: route('webhooks.shkeeper'));
        } catch (ShkeeperException $e) {
            Log::error('Failed to create Shkeeper invoice for order {public_id}: {reason}', ['public_id' => $order->public_id, 'reason' => $e->getMessage()]);
            throw new UserFacingException(__('The payment provider could not create an invoice for order :order. Your order is saved; try again in a few minutes or choose another currency.', ['order' => $order->shortId()]));
        }

        return DB::transaction(function () use ($order, $invoice) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== OrderStatus::Pending) {
                throw new UserFacingException(__('Order :order is :status; no payment is needed.', ['order' => $locked->shortId(), 'status' => mb_strtolower($locked->status->label())]));
            }

            $payment = Payment::query()->where('order_id', $locked->id)->where('kind', PaymentKind::Charge->value)
                ->where('provider', PaymentProvider::Shkeeper->value)->lockForUpdate()->first();
            if ($payment?->status === PaymentStatus::Confirmed) {
                throw new UserFacingException(__('Order :order is already paid.', ['order' => $locked->shortId()]));
            }

            $isNew = $payment === null;
            $payment ??= new Payment([
                'order_id' => $locked->id,
                'kind' => PaymentKind::Charge,
                'provider' => PaymentProvider::Shkeeper,
                'status' => PaymentStatus::Pending,
            ]);
            $payment->fill([
                'provider_reference' => $invoice->id,
                'amount_minor' => $locked->total_minor,
                'currency' => $locked->currency,
                'crypto' => $invoice->crypto,
                'crypto_amount' => $invoice->cryptoAmount,
                'wallet_address' => $invoice->wallet,
                'quoted_at' => now(),
                'quote_recalculate_after' => $invoice->recalculateAfter,
            ]);
            $payment->save();

            if ($isNew) {
                Mail::to($locked->buyer)->queue((new OrderPlacedMail($locked, $payment))->afterCommit());
            }

            Log::info('Shkeeper invoice {invoice_id} created for order {public_id} in {crypto}', [
                'invoice_id' => $invoice->id,
                'public_id' => $locked->public_id,
                'crypto' => $invoice->crypto,
            ]);

            return $payment;
        });
    }

    public function payWithBalance(Order $order, User $buyer): Payment
    {
        $payment = DB::transaction(function () use ($order, $buyer) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->buyer_id !== $buyer->id) {
                throw new UserFacingException(__('You can only pay for your own orders.'));
            }
            if ($locked->status !== OrderStatus::Pending) {
                throw new UserFacingException(__('Order :order is :status; no payment is needed.', ['order' => $locked->shortId(), 'status' => mb_strtolower($locked->status->label())]));
            }
            if ($locked->total_minor > 0 && ! config('shop.payments_balance_enabled')) {
                throw new UserFacingException(__('Paying with the shop balance is switched off right now. Choose another payment method.'));
            }
            if ($locked->total_minor === 0) {
                // Fully discounted order: nothing to debit.
                $payment = null;
            } else {
                $payment = Payment::create([
                    'order_id' => $locked->id,
                    'kind' => PaymentKind::Charge,
                    'provider' => PaymentProvider::Balance,
                    'amount_minor' => $locked->total_minor,
                    'received_minor' => $locked->total_minor,
                    'currency' => $locked->currency,
                    'status' => PaymentStatus::Confirmed,
                    'confirmed_at' => now(),
                ]);
                $this->balances->debit($buyer, $locked->total_minor, $locked->currency, 'order_payment', $locked);
            }
            $this->orders->markPaid($locked);

            return $payment;
        });

        $this->afterPaid($order->fresh());

        return $payment ?? new Payment;
    }

    /**
     * Applies a verified Shkeeper notification. Safe to call any number of
     * times for the same invoice: once the payment is confirmed, later calls
     * change nothing.
     *
     * @return array{status: string, message: string}
     */
    public function handleShkeeperNotification(array $payload): array
    {
        $externalId = (string) ($payload['external_id'] ?? '');
        if (! Str::isUuid($externalId)) {
            return ['status' => 'ignored', 'message' => 'external_id is not an order id'];
        }

        $paidNow = false;
        $result = DB::transaction(function () use ($payload, $externalId, &$paidNow) {
            $order = Order::query()->where('public_id', $externalId)->lockForUpdate()->first();
            if ($order === null) {
                return ['status' => 'ignored', 'message' => "unknown order {$externalId}"];
            }
            $payment = Payment::query()->where('order_id', $order->id)->where('kind', PaymentKind::Charge->value)
                ->where('provider', PaymentProvider::Shkeeper->value)->lockForUpdate()->first();
            if ($payment === null) {
                return ['status' => 'ignored', 'message' => "no Shkeeper payment for order {$order->public_id}"];
            }
            if ($payment->status === PaymentStatus::Confirmed) {
                Log::info('Duplicate Shkeeper notification for order {public_id} ignored; payment {reference} already confirmed', [
                    'public_id' => $order->public_id,
                    'reference' => $payment->provider_reference,
                ]);

                return ['status' => 'ignored', 'message' => 'payment already confirmed'];
            }

            $payment->raw_payload_json = $payload;
            $fiat = (string) ($payload['fiat'] ?? '');
            if ($fiat !== $order->currency) {
                return $this->reject($order, $payment, sprintf(
                    'Payment currency mismatch. Expected %s, received %s. Order has not been marked as paid.',
                    $order->currency,
                    $fiat === '' ? 'no currency' : $fiat,
                ));
            }

            try {
                $received = Money::parseReceived((string) ($payload['balance_fiat'] ?? ''), $order->currency);
            } catch (InvalidArgumentException $e) {
                return $this->reject($order, $payment, 'Payment notification carried an unreadable amount. Order has not been marked as paid.');
            }
            $payment->received_minor = $received;

            $status = strtoupper((string) ($payload['status'] ?? ''));
            $paidFlag = filter_var($payload['paid'] ?? false, FILTER_VALIDATE_BOOL);
            if (! $paidFlag || ! in_array($status, ['PAID', 'OVERPAID'], true)) {
                $payment->status = $received > 0 ? PaymentStatus::Partial : PaymentStatus::Pending;
                $payment->save();

                return ['status' => 'processed', 'message' => "invoice {$status}, received ".Money::format($received, $order->currency)];
            }

            if ($received < $order->total_minor) {
                return $this->reject($order, $payment, sprintf(
                    'Payment amount mismatch. Expected %s, received %s. Order has not been marked as paid.',
                    Money::format($order->total_minor, $order->currency),
                    Money::format($received, $order->currency),
                ));
            }

            $payment->status = PaymentStatus::Confirmed;
            $payment->confirmed_at = now();
            $payment->failure_reason = null;
            $payment->save();

            if ($order->status === OrderStatus::Pending) {
                $paidNow = $this->orders->markPaid($order);
                if ($received > $order->total_minor) {
                    $this->creditOverpayment($order, $payment, $received - $order->total_minor);
                }

                return ['status' => 'processed', 'message' => "order {$order->public_id} paid"];
            }

            return $this->handleLatePayment($order, $payment, $received);
        });

        if ($paidNow) {
            $this->afterPaid(Order::query()->where('public_id', $externalId)->firstOrFail());
        }

        return $result;
    }

    /**
     * Polls Shkeeper for pending invoices, covering webhooks that never
     * arrived. This is a server-to-server check, not a client signal.
     */
    public function reconcilePending(int $olderThanMinutes = 2): int
    {
        $count = 0;
        $payments = Payment::query()->with('order')
            ->where('provider', PaymentProvider::Shkeeper->value)->where('kind', PaymentKind::Charge->value)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Partial->value, PaymentStatus::Rejected->value])
            ->where('created_at', '<', now()->subMinutes($olderThanMinutes))
            ->where('created_at', '>', now()->subDays(7))
            ->limit(200)->get();

        foreach ($payments as $payment) {
            try {
                $status = $this->shkeeper->getInvoiceStatus($payment->order->public_id);
            } catch (ShkeeperException $e) {
                Log::warning('Shkeeper status poll failed for order {public_id}: {reason}', ['public_id' => $payment->order->public_id, 'reason' => $e->getMessage()]);

                continue;
            }
            if ($status === null) {
                continue;
            }
            $result = $this->handleShkeeperNotification($status->toNotificationPayload());
            $count += $result['status'] === 'processed' ? 1 : 0;
        }

        return $count;
    }

    /**
     * Delivery (followed by the "order paid" email) and the PDF invoice run as
     * queued jobs with their own retries, so a delivery failure never undoes
     * or blocks the recorded payment.
     */
    public function afterPaid(Order $order): void
    {
        DeliverOrder::dispatch($order->id)->afterCommit();
        GenerateInvoicePdf::dispatch($order->id)->afterCommit();
    }

    /**
     * Money arrived for an order that already expired or was cancelled. The
     * stock may be gone, so the amount is credited to the buyer's balance.
     */
    private function handleLatePayment(Order $order, Payment $payment, int $received): array
    {
        try {
            $this->balances->credit($order->buyer, $received, $order->currency, 'late_payment_credit', $payment,
                "Payment for {$order->status->value} order {$order->shortId()}");
        } catch (UserFacingException $e) {
            $payment->failure_reason = 'Late payment needs a manual refund: '.$e->getMessage();
            $payment->save();
            Log::error('Late payment for order {public_id} could not be credited: {reason}', ['public_id' => $order->public_id, 'reason' => $e->getMessage()]);
            $this->audit->log('payment.late_unresolved', $payment, ['order' => $order->public_id]);

            return ['status' => 'processed', 'message' => 'late payment requires manual refund'];
        }

        Log::warning('Late payment for {status} order {public_id} credited to buyer balance', ['status' => $order->status->value, 'public_id' => $order->public_id]);
        $this->audit->log('payment.late_credited', $payment, ['order' => $order->public_id, 'amount' => Money::format($received, $order->currency)]);

        return ['status' => 'processed', 'message' => 'late payment credited to balance'];
    }

    /**
     * The buyer sent more than the order total. The difference goes to the
     * buyer's balance when the currency rules allow it, otherwise staff
     * resolve it manually (recorded on the payment and in the audit log).
     */
    private function creditOverpayment(Order $order, Payment $payment, int $overpaid): void
    {
        try {
            $this->balances->credit($order->buyer, $overpaid, $order->currency, 'overpayment_credit', $payment,
                "Overpayment on order {$order->shortId()}");
            $this->audit->log('payment.overpayment_credited', $payment, ['order' => $order->public_id, 'amount' => Money::format($overpaid, $order->currency)]);
            Log::info('Overpayment of {amount} on order {public_id} credited to buyer balance', ['amount' => Money::format($overpaid, $order->currency), 'public_id' => $order->public_id]);
        } catch (UserFacingException $e) {
            $payment->failure_reason = 'Overpayment of '.Money::format($overpaid, $order->currency).' needs manual handling: '.$e->getMessage();
            $payment->save();
            $this->audit->log('payment.overpayment_unresolved', $payment, ['order' => $order->public_id, 'amount' => Money::format($overpaid, $order->currency)]);
        }
    }

    public function quoteIsStale(Payment $payment): bool
    {
        return $payment->quoted_at === null || $payment->quoted_at->lt(now()->subMinutes((int) config('shop.quote_ttl_minutes')));
    }

    private function reject(Order $order, Payment $payment, string $reason): array
    {
        $payment->status = PaymentStatus::Rejected;
        $payment->failure_reason = $reason;
        $payment->save();

        Log::warning('Shkeeper notification rejected for order {public_id}: {reason}', ['public_id' => $order->public_id, 'reason' => $reason]);
        $this->audit->log('payment.rejected', $payment, ['order' => $order->public_id, 'reason' => $reason]);

        return ['status' => 'rejected', 'message' => $reason];
    }
}
