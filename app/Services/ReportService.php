<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales figures for the admin reports page. Money stays per currency; no
 * figure ever adds USD to EUR.
 */
class ReportService
{
    private const PAID = ['paid', 'delivered', 'partially_refunded', 'refunded'];

    /**
     * @return array<string, list<array{date: string, minor: int}>> net revenue per day, keyed by currency
     */
    public function dailyRevenue(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('orders')->whereIn('status', self::PAID)->whereBetween('paid_at', [$from, $to])
            ->groupBy('currency', DB::raw('DATE(paid_at)'))
            ->selectRaw('currency, DATE(paid_at) AS day, SUM(total_minor - refunded_minor) AS net')->get();

        $out = [];
        foreach ($rows->groupBy('currency') as $currency => $days) {
            $byDay = $days->keyBy(fn ($r) => (string) $r->day);
            $series = [];
            for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
                $series[] = ['date' => $d->toDateString(), 'minor' => (int) ($byDay[$d->toDateString()]->net ?? 0)];
            }
            $out[$currency] = $series;
        }
        ksort($out);

        return $out;
    }

    /** @return Collection<int, object> gross, refunded, orders per currency */
    public function totals(Carbon $from, Carbon $to): Collection
    {
        return DB::table('orders')->whereIn('status', self::PAID)->whereBetween('paid_at', [$from, $to])
            ->groupBy('currency')
            ->selectRaw('currency, COUNT(*) AS orders, SUM(total_minor) AS gross, SUM(refunded_minor) AS refunded, SUM(discount_minor) AS discounts')
            ->orderBy('currency')->get();
    }

    /** @return array{created: int, paid: int, rate: ?float} share of orders created in the period that were paid */
    public function conversion(Carbon $from, Carbon $to): array
    {
        $created = DB::table('orders')->whereBetween('created_at', [$from, $to])->count();
        $paid = DB::table('orders')->whereBetween('created_at', [$from, $to])->whereIn('status', self::PAID)->count();

        return ['created' => $created, 'paid' => $paid, 'rate' => $created > 0 ? $paid / $created : null];
    }

    /** @return Collection<int, object> */
    public function topProducts(Carbon $from, Carbon $to, int $limit = 15): Collection
    {
        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::PAID)->whereBetween('orders.paid_at', [$from, $to])
            ->groupBy('order_items.product_id', 'order_items.title', 'orders.currency')
            ->selectRaw('order_items.product_id, order_items.title, orders.currency, SUM(order_items.quantity) AS units, SUM(order_items.unit_price_minor * order_items.quantity - order_items.discount_minor - order_items.refunded_minor) AS net')
            ->orderByDesc('net')->limit($limit)->get();
    }

    /** @return Collection<int, object> */
    public function topSellers(Carbon $from, Carbon $to, int $limit = 15): Collection
    {
        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('users', 'users.id', '=', 'order_items.seller_id')
            ->leftJoin('seller_profiles', 'seller_profiles.user_id', '=', 'users.id')
            ->whereIn('orders.status', self::PAID)->whereBetween('orders.paid_at', [$from, $to])
            ->groupBy('order_items.seller_id', 'users.email', 'seller_profiles.display_name', 'orders.currency')
            ->selectRaw('order_items.seller_id, users.email, seller_profiles.display_name, orders.currency, COUNT(DISTINCT orders.id) AS orders, SUM(order_items.unit_price_minor * order_items.quantity - order_items.discount_minor - order_items.refunded_minor) AS net, SUM(order_items.seller_earning_minor) AS earnings')
            ->orderByDesc('net')->limit($limit)->get();
    }
}
