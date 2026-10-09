<?php

namespace App\Services;

use App\Enums\DisputeStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\WebhookEventStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the admin dashboard. Money stays per currency (one currency
 * is shown at a time); counts span all currencies. Every KPI comes with the
 * same figure for the previous period of equal length.
 */
class AnalyticsService
{
    private const PAID = ['paid', 'delivered', 'partially_refunded', 'refunded'];

    public function __construct(private readonly ReportService $reports) {}

    /**
     * @return array<string, array{value: int|float|null, previous: int|float|null}>
     */
    public function kpis(Carbon $from, Carbon $to, string $currency): array
    {
        $length = $from->diffInSeconds($to);
        $prevFrom = $from->copy()->subSeconds((int) $length);
        // The current period is open-ended: timestamp(0) columns round to the
        // second, so an upper bound of "now" could miss the latest orders.
        $current = $this->periodFigures($from, null, $currency);
        $previous = $this->periodFigures($prevFrom, $from, $currency);

        $out = [];
        foreach ($current as $key => $value) {
            $out[$key] = ['value' => $value, 'previous' => $previous[$key]];
        }

        return $out;
    }

    /** @return array<string, int|float|null> */
    private function periodFigures(Carbon $from, ?Carbon $to, string $currency): array
    {
        $before = fn ($q, string $column) => $to === null ? $q : $q->where($column, '<', $to);
        $orders = $before(DB::table('orders')->whereIn('status', self::PAID)->where('paid_at', '>=', $from), 'paid_at');
        $money = (clone $orders)->where('currency', $currency)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_minor - refunded_minor), 0) AS net')->first();
        // Platform revenue = what buyers paid for the lines minus what sellers earn.
        $commission = (int) $before(DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::PAID)->where('orders.paid_at', '>=', $from), 'orders.paid_at')
            ->where('orders.currency', $currency)
            ->sum(DB::raw('order_items.unit_price_minor * order_items.quantity - order_items.discount_minor - order_items.refunded_minor - order_items.seller_earning_minor'));
        $created = $before(DB::table('orders')->where('created_at', '>=', $from), 'created_at')->count();
        $paidCreated = $before(DB::table('orders')->where('created_at', '>=', $from), 'created_at')->whereIn('status', self::PAID)->count();

        return [
            'net_revenue' => (int) $money->net,
            'commission' => $commission,
            'orders' => (clone $orders)->count(),
            'average_order' => $money->n > 0 ? intdiv((int) $money->net, (int) $money->n) : null,
            'active_sellers' => $before(DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('orders.status', self::PAID)->where('orders.paid_at', '>=', $from), 'orders.paid_at')
                ->distinct()->count('order_items.seller_id'),
            'conversion' => $created > 0 ? round($paidCreated / $created * 100, 1) : null,
        ];
    }

    /** @return list<array{date: string, minor: int}> */
    public function dailyRevenue(Carbon $from, Carbon $to, string $currency): array
    {
        return $this->reports->dailyRevenue($from, $to)[$currency] ?? $this->emptySeries($from, $to);
    }

    /** @return list<array{date: string, minor: int}> paid orders per day ('minor' holds the count) */
    public function dailyOrders(Carbon $from, Carbon $to): array
    {
        $byDay = DB::table('orders')->whereIn('status', self::PAID)->whereBetween('paid_at', [$from, $to])
            ->groupBy(DB::raw('DATE(paid_at)'))->selectRaw('DATE(paid_at) AS day, COUNT(*) AS n')->pluck('n', 'day');
        $series = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $series[] = ['date' => $d->toDateString(), 'minor' => (int) ($byDay[$d->toDateString()] ?? 0)];
        }

        return $series;
    }

    public function topProducts(Carbon $from, Carbon $to, string $currency, int $limit = 5): Collection
    {
        return $this->reports->topProducts($from, $to, 50)->where('currency', $currency)->take($limit)->values();
    }

    public function topSellers(Carbon $from, Carbon $to, string $currency, int $limit = 5): Collection
    {
        return $this->reports->topSellers($from, $to, 50)->where('currency', $currency)->take($limit)->values();
    }

    public function approvedSellers(): int
    {
        return DB::table('seller_profiles')->where('status', 'approved')->count();
    }

    /**
     * Payment gateway health at a glance. Each check has a level (good,
     * warning, critical) that the view shows with a text label, never by
     * colour alone.
     *
     * @return list<array{label: string, value: string, level: string, hint: string}>
     */
    public function gatewayHealth(): array
    {
        $day = now()->subDay();
        $lastOk = DB::table('gateway_logs')->where('channel', 'webhook')->where('outcome', 'ok')->max('created_at');
        $rejected = DB::table('gateway_logs')->where('created_at', '>=', $day)->where('outcome', 'rejected_signature')->count();
        $apiErrors = DB::table('gateway_logs')->where('created_at', '>=', $day)->where('channel', 'api')->whereIn('outcome', ['error', 'timeout'])->count();
        $apiOk = DB::table('gateway_logs')->where('created_at', '>=', $day)->where('channel', 'api')->where('outcome', 'ok')->count();
        $stuck = DB::table('payments')->where('provider', PaymentProvider::Shkeeper->value)->where('kind', 'charge')
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Partial->value])->where('created_at', '<', now()->subHour())
            ->whereExists(fn ($q) => $q->from('orders')->whereColumn('orders.id', 'payments.order_id')->where('orders.status', 'pending'))->count();
        $failedEvents = DB::table('webhook_events')->whereIn('status', [WebhookEventStatus::Failed->value, WebhookEventStatus::Dead->value])->count();
        $pendingSince = DB::table('payments')->where('provider', PaymentProvider::Shkeeper->value)->where('status', PaymentStatus::Pending->value)
            ->where('created_at', '>=', $day)->count();

        $lastAge = $lastOk ? Carbon::parse($lastOk)->diffForHumans() : null;

        return [
            [
                'label' => __('Last accepted payment callback'),
                'value' => $lastAge ?? __('never'),
                // Silence is only suspicious while invoices are waiting for payment.
                'level' => $lastOk === null ? ($pendingSince > 0 ? 'warning' : 'good') : 'good',
                'hint' => $lastOk === null && $pendingSince > 0 ? __('Invoices were created but no callback arrived. Check the callback URL.') : '',
            ],
            [
                'label' => __('Rejected signatures (24 h)'),
                'value' => (string) $rejected,
                'level' => $rejected === 0 ? 'good' : ($rejected < 10 ? 'warning' : 'critical'),
                'hint' => $rejected > 0 ? __('Check SHKEEPER_WEBHOOK_SECRET and the server clock, or someone is probing the endpoint.') : '',
            ],
            [
                'label' => __('API errors and timeouts (24 h)'),
                'value' => __(':errors of :total calls', ['errors' => $apiErrors, 'total' => $apiErrors + $apiOk]),
                'level' => $apiErrors === 0 ? 'good' : ($apiErrors <= max(2, intdiv($apiOk, 10)) ? 'warning' : 'critical'),
                'hint' => $apiErrors > 0 ? __('See the gateway log for details.') : '',
            ],
            [
                'label' => __('Payments waiting over 1 hour'),
                'value' => (string) $stuck,
                'level' => $stuck === 0 ? 'good' : 'warning',
                'hint' => $stuck > 0 ? __('Usually buyers who never paid; the reconciliation job checks them every 5 minutes.') : '',
            ],
            [
                'label' => __('Failed webhook events'),
                'value' => (string) $failedEvents,
                'level' => $failedEvents === 0 ? 'good' : 'critical',
                'hint' => $failedEvents > 0 ? __('Retry them on the Webhooks page after fixing the cause.') : '',
            ],
        ];
    }

    /** @return array<string, int> */
    public function attention(): array
    {
        return [
            'disputes' => DB::table('disputes')->whereIn('status', DisputeStatus::openValues())->count(),
        ];
    }

    /** @return list<array{date: string, minor: int}> */
    private function emptySeries(Carbon $from, Carbon $to): array
    {
        $series = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $series[] = ['date' => $d->toDateString(), 'minor' => 0];
        }

        return $series;
    }
}
