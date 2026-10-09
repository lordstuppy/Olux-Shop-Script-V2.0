<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Exceptions\UserFacingException;
use App\Models\OrderItem;
use App\Models\Payout;
use App\Models\SellerLedgerEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seller earnings ledger, payout requests and reconciliation.
 *
 * For every paid order item the ledger holds:
 *   sale (+net) + commission (-fee) + refund_adjustment (delta per refund)
 * which always sums to OrderItem::seller_earning_minor. Payouts debit the
 * ledger when requested and are reversed if rejected.
 */
class PayoutService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public static function earningFor(int $netMinor, int $commissionBps): int
    {
        return $netMinor - Money::applyBps($netMinor, $commissionBps);
    }

    /** Called inside the transaction that marks the order paid. */
    public function recordSale(OrderItem $item, string $currency): void
    {
        $net = $item->netMinor();
        $fee = Money::applyBps($net, $item->commission_bps);

        $this->entry($item->seller_id, 'sale', $net, $currency, $item->id);
        if ($fee > 0) {
            $this->entry($item->seller_id, 'commission', -$fee, $currency, $item->id);
        }

        $item->seller_earning_minor = $net - $fee;
        $item->save();
    }

    /** Called after OrderItem::refunded_minor has been increased. */
    public function adjustForRefund(OrderItem $item, string $currency): void
    {
        $newEarning = self::earningFor($item->netMinor() - $item->refunded_minor, $item->commission_bps);
        $delta = $newEarning - $item->seller_earning_minor;
        if ($delta !== 0) {
            $this->entry($item->seller_id, 'refund_adjustment', $delta, $currency, $item->id);
        }
        $item->seller_earning_minor = $newEarning;
        $item->save();
    }

    /**
     * @return array<string, array{total: int, pending: int, available: int}> keyed by currency
     */
    public function balances(User $seller): array
    {
        $cutoff = now()->subDays((int) config('shop.payout_hold_days'));

        $totals = SellerLedgerEntry::query()->where('seller_id', $seller->id)
            ->groupBy('currency')->selectRaw('currency, SUM(amount_minor) AS total')->pluck('total', 'currency');
        $pending = SellerLedgerEntry::query()->where('seller_id', $seller->id)
            ->whereIn('type', ['sale', 'commission'])->where('created_at', '>', $cutoff)
            ->groupBy('currency')->selectRaw('currency, SUM(amount_minor) AS total')->pluck('total', 'currency');

        $result = [];
        foreach ($totals as $currency => $total) {
            $held = max(0, (int) ($pending[$currency] ?? 0));
            $result[$currency] = [
                'total' => (int) $total,
                'pending' => $held,
                'available' => max(0, (int) $total - $held),
            ];
        }

        return $result;
    }

    public function requestPayout(User $seller, int $amountMinor, string $currency): Payout
    {
        $profile = $seller->sellerProfile;
        if ($profile === null || ! $seller->isSeller()) {
            throw new UserFacingException('Only approved sellers can request payouts.');
        }
        $minimum = (int) config('shop.min_payout_minor');
        if ($amountMinor < $minimum) {
            throw new UserFacingException('The minimum payout is '.Money::format($minimum, $currency).'.');
        }

        return DB::transaction(function () use ($seller, $profile, $amountMinor, $currency) {
            // Serialises payout requests per seller so the available amount cannot be spent twice.
            User::query()->whereKey($seller->id)->lockForUpdate()->first();

            $available = $this->balances($seller)[$currency]['available'] ?? 0;
            if ($amountMinor > $available) {
                throw new UserFacingException(sprintf(
                    'Requested %s but only %s is available for payout.',
                    Money::format($amountMinor, $currency),
                    Money::format($available, $currency),
                ));
            }

            $payout = Payout::create([
                'seller_id' => $seller->id,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'destination' => $profile->payout_address,
                'status' => PayoutStatus::Requested,
            ]);
            $this->entry($seller->id, 'payout', -$amountMinor, $currency, null, $payout->id);
            $this->audit->log('payout.requested', $payout, ['amount' => Money::format($amountMinor, $currency)], $seller);

            return $payout;
        });
    }

    public function approve(Payout $payout, User $admin): void
    {
        $this->transition($payout, PayoutStatus::Approved, $admin, [PayoutStatus::Requested]);
    }

    public function markPaid(Payout $payout, User $admin, string $reference): void
    {
        $this->transition($payout, PayoutStatus::Paid, $admin, [PayoutStatus::Requested, PayoutStatus::Approved], $reference);
    }

    public function reject(Payout $payout, User $admin, string $note): void
    {
        DB::transaction(function () use ($payout, $admin, $note) {
            $this->transition($payout, PayoutStatus::Rejected, $admin, [PayoutStatus::Requested, PayoutStatus::Approved], null, $note);
            $this->entry($payout->seller_id, 'payout_reversal', $payout->amount_minor, $payout->currency, null, $payout->id);
        });
    }

    /**
     * Compares the ledger against completed orders and payouts.
     *
     * @return list<array{seller_id: int, currency: string, check: string, expected: int, actual: int}>
     */
    public function reconcile(): array
    {
        $paidStates = array_map(fn (OrderStatus $s) => $s->value, array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isPaidState()));
        $mismatches = [];

        // 1. Each item's stored earning must follow from its amounts.
        OrderItem::query()->whereHas('order', fn ($q) => $q->whereIn('status', $paidStates))->with('order:id,currency')
            ->chunkById(500, function ($items) use (&$mismatches) {
                foreach ($items as $item) {
                    $expected = self::earningFor($item->netMinor() - $item->refunded_minor, $item->commission_bps);
                    if ($expected !== $item->seller_earning_minor) {
                        $mismatches[] = ['seller_id' => $item->seller_id, 'currency' => $item->order->currency, 'check' => "order_item {$item->id} earning", 'expected' => $expected, 'actual' => $item->seller_earning_minor];
                    }
                }
            });

        // 2. Ledger sales per seller and currency must equal the earnings of completed orders.
        $expectedSales = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', $paidStates)
            ->groupBy('order_items.seller_id', 'orders.currency')
            ->selectRaw('order_items.seller_id, orders.currency, SUM(order_items.seller_earning_minor) AS total')->get();
        $ledgerSales = DB::table('seller_ledger_entries')->whereIn('type', ['sale', 'commission', 'refund_adjustment'])
            ->groupBy('seller_id', 'currency')->selectRaw('seller_id, currency, SUM(amount_minor) AS total')->get();
        $mismatches = array_merge($mismatches, $this->compare($expectedSales, $ledgerSales, 'sales'));

        // 3. Ledger payout entries must equal non-rejected payouts.
        $expectedPayouts = DB::table('payouts')->where('status', '!=', PayoutStatus::Rejected->value)
            ->groupBy('seller_id', 'currency')->selectRaw('seller_id, currency, -SUM(amount_minor) AS total')->get();
        $ledgerPayouts = DB::table('seller_ledger_entries')->whereIn('type', ['payout', 'payout_reversal'])
            ->groupBy('seller_id', 'currency')->selectRaw('seller_id, currency, SUM(amount_minor) AS total')->get();
        $mismatches = array_merge($mismatches, $this->compare($expectedPayouts, $ledgerPayouts, 'payouts'));

        foreach ($mismatches as $m) {
            Log::error('Payout reconciliation mismatch for seller {seller_id} {currency} ({check}): expected {expected}, ledger {actual}', $m);
        }

        return $mismatches;
    }

    private function compare($expectedRows, $actualRows, string $check): array
    {
        $index = fn ($rows) => collect($rows)->mapWithKeys(fn ($r) => [$r->seller_id.'|'.$r->currency => (int) $r->total]);
        $expected = $index($expectedRows);
        $actual = $index($actualRows);
        $out = [];
        foreach ($expected->keys()->merge($actual->keys())->unique() as $key) {
            $e = $expected->get($key, 0);
            $a = $actual->get($key, 0);
            if ($e !== $a) {
                [$sellerId, $currency] = explode('|', $key);
                $out[] = ['seller_id' => (int) $sellerId, 'currency' => $currency, 'check' => $check, 'expected' => $e, 'actual' => $a];
            }
        }

        return $out;
    }

    private function transition(Payout $payout, PayoutStatus $to, User $admin, array $from, ?string $reference = null, ?string $note = null): void
    {
        DB::transaction(function () use ($payout, $to, $admin, $from, $reference, $note) {
            $locked = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, $from, true)) {
                throw new UserFacingException("Payout #{$locked->id} is {$locked->status->value} and cannot be marked {$to->value}.");
            }
            $locked->status = $to;
            $locked->processed_by = $admin->id;
            $locked->processed_at = now();
            if ($reference !== null) {
                $locked->reference = $reference;
            }
            if ($note !== null) {
                $locked->note = $note;
            }
            $locked->save();
            $payout->setRawAttributes($locked->getAttributes(), true);
            $this->audit->log('payout.'.$to->value, $locked, array_filter(['reference' => $reference, 'note' => $note]), $admin);
        });
    }

    private function entry(int $sellerId, string $type, int $amount, string $currency, ?int $orderItemId = null, ?int $payoutId = null): void
    {
        SellerLedgerEntry::create([
            'seller_id' => $sellerId,
            'order_item_id' => $orderItemId,
            'payout_id' => $payoutId,
            'type' => $type,
            'amount_minor' => $amount,
            'currency' => $currency,
        ]);
    }
}
