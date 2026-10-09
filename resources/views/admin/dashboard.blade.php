@extends('layouts.app')

@section('title', __('Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Admin overview') }}</h1>

    <h2>{{ __('Needs attention') }}</h2>
    <ul class="stats">
        @foreach ($attention as $row)
            <li><span>@if ($row['route'])<a href="{{ $row['route'] }}">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif</span><strong>{{ number_format($row['value']) }}</strong></li>
        @endforeach
    </ul>

    @isset($kpis)
        @php
            $delta = function (array $kpi, bool $money) use ($currency) {
                if ($kpi['previous'] === null || $kpi['value'] === null) { return __('no earlier data'); }
                if ($kpi['previous'] == 0) { return $kpi['value'] == 0 ? __('same as the previous period') : __('up from 0 in the previous period'); }
                $pct = round(($kpi['value'] - $kpi['previous']) / abs($kpi['previous']) * 100);
                $was = $money ? money((int) $kpi['previous'], $currency) : number_format($kpi['previous'], is_float($kpi['previous']) ? 1 : 0);
                return $pct == 0 ? __('same as the previous period (:was)', ['was' => $was])
                    : ($pct > 0 ? __('up :pct% from :was', ['pct' => $pct, 'was' => $was]) : __('down :pct% from :was', ['pct' => abs($pct), 'was' => $was]));
            };
        @endphp
        <form class="filters period-filter mt" method="get" aria-label="{{ __('Analytics period') }}">
            <x-select name="days" :label="__('Period')" :options="collect(\App\Http\Controllers\Admin\DashboardController::PERIODS)->mapWithKeys(fn ($d) => [$d => __('Last :days days', ['days' => $d])])->all()" :value="$days" />
            <x-select name="currency" :label="__('Currency')" :options="array_combine(\App\Support\Money::supported(), \App\Support\Money::supported())" :value="$currency" />
            <button type="submit" class="btn-secondary">{{ __('Show') }}</button>
        </form>

        <h2>{{ __('Last :days days in :currency', ['days' => $days, 'currency' => $currency]) }}</h2>
        <ul class="kpis">
            <li><span>{{ __('Net revenue') }}</span><strong>{{ money($kpis['net_revenue']['value'], $currency) }}</strong><span class="delta">{{ $delta($kpis['net_revenue'], true) }}</span></li>
            <li><span>{{ __('Platform commission') }}</span><strong>{{ money($kpis['commission']['value'], $currency) }}</strong><span class="delta">{{ $delta($kpis['commission'], true) }}</span></li>
            <li><span>{{ __('Paid orders (all currencies)') }}</span><strong>{{ number_format($kpis['orders']['value']) }}</strong><span class="delta">{{ $delta($kpis['orders'], false) }}</span></li>
            <li><span>{{ __('Average order') }}</span><strong>{{ $kpis['average_order']['value'] === null ? '-' : money($kpis['average_order']['value'], $currency) }}</strong><span class="delta">{{ $delta($kpis['average_order'], true) }}</span></li>
            <li><span>{{ __('Active sellers') }}</span><strong>{{ number_format($kpis['active_sellers']['value']) }} <span class="muted">/ {{ number_format($approvedSellers) }}</span></strong><span class="delta">{{ __('with a sale in the period; :delta', ['delta' => $delta($kpis['active_sellers'], false)]) }}</span></li>
            <li><span>{{ __('Checkout conversion') }}</span><strong>{{ $kpis['conversion']['value'] === null ? '-' : $kpis['conversion']['value'].'%' }}</strong><span class="delta">{{ __('orders placed that were paid; :delta', ['delta' => $delta($kpis['conversion'], false)]) }}</span></li>
        </ul>

        <div class="two-col mt">
            <section aria-labelledby="revenue-heading">
                <h3 id="revenue-heading">{{ __('Revenue trend') }}</h3>
                @include('partials.column-chart', ['series' => $revenueSeries, 'currency' => $currency, 'chartId' => 'dash-revenue', 'caption' => __('Net revenue per day in :currency, last :days days', ['currency' => $currency, 'days' => $days])])
                <details>
                    <summary>{{ __('Revenue table') }}</summary>
                    <div class="table-wrap"><table><thead><tr><th scope="col">{{ __('Day') }}</th><th scope="col" class="num">{{ __('Net revenue') }}</th></tr></thead>
                        <tbody>@foreach ($revenueSeries as $p)<tr><td>{{ $p['date'] }}</td><td class="num">{{ money($p['minor'], $currency) }}</td></tr>@endforeach</tbody></table></div>
                </details>
            </section>
            <section aria-labelledby="orders-heading">
                <h3 id="orders-heading">{{ __('Order volume') }}</h3>
                @include('partials.column-chart', ['series' => $orderSeries, 'currency' => $currency, 'kind' => 'count', 'chartId' => 'dash-orders', 'caption' => __('Paid orders per day, all currencies, last :days days', ['days' => $days])])
                <details>
                    <summary>{{ __('Order table') }}</summary>
                    <div class="table-wrap"><table><thead><tr><th scope="col">{{ __('Day') }}</th><th scope="col" class="num">{{ __('Paid orders') }}</th></tr></thead>
                        <tbody>@foreach ($orderSeries as $p)<tr><td>{{ $p['date'] }}</td><td class="num">{{ $p['minor'] }}</td></tr>@endforeach</tbody></table></div>
                </details>
            </section>
        </div>

        <div class="two-col">
            <section aria-labelledby="top-products-heading">
                <h3 id="top-products-heading">{{ __('Top products') }}</h3>
                @if ($topProducts->isEmpty())
                    <p>{{ __('No sales in this period.') }}</p>
                @else
                    <div class="table-wrap"><table>
                        <thead><tr><th scope="col">{{ __('Product') }}</th><th scope="col" class="num">{{ __('Units') }}</th><th scope="col" class="num">{{ __('Net') }}</th></tr></thead>
                        <tbody>@foreach ($topProducts as $row)<tr><td>{{ $row->title }}</td><td class="num">{{ $row->units }}</td><td class="num">{{ money((int) $row->net, $currency) }}</td></tr>@endforeach</tbody>
                    </table></div>
                @endif
            </section>
            <section aria-labelledby="top-sellers-heading">
                <h3 id="top-sellers-heading">{{ __('Top sellers') }}</h3>
                @if ($topSellers->isEmpty())
                    <p>{{ __('No sales in this period.') }}</p>
                @else
                    <div class="table-wrap"><table>
                        <thead><tr><th scope="col">{{ __('Seller') }}</th><th scope="col" class="num">{{ __('Orders') }}</th><th scope="col" class="num">{{ __('Net') }}</th></tr></thead>
                        <tbody>@foreach ($topSellers as $row)<tr><td>{{ $row->display_name ?? $row->email }}</td><td class="num">{{ $row->orders }}</td><td class="num">{{ money((int) $row->net, $currency) }}</td></tr>@endforeach</tbody>
                    </table></div>
                @endif
            </section>
        </div>

        <section aria-labelledby="gateway-heading">
            <h3 id="gateway-heading">{{ __('Payment gateway health') }}</h3>
            <ul class="health">
                @foreach ($gateway as $check)
                    <li>
                        <span class="level level-{{ $check['level'] }}">{{ match ($check['level']) { 'good' => __('OK'), 'warning' => __('Check'), default => __('Problem') } }}</span>
                        <span><strong>{{ $check['label'] }}:</strong> {{ $check['value'] }}@if ($check['hint'] !== '')<br><span class="hint">{{ $check['hint'] }}</span>@endif</span>
                    </li>
                @endforeach
            </ul>
            @can('gateway.view')<p><a href="{{ route('admin.gateway.index') }}">{{ __('Open the payment gateway page') }}</a></p>@endcan
        </section>
    @endisset

    <h2>{{ __('Recent orders') }}</h2>
    @include('admin.orders.table', ['orders' => $recentOrders])
@endsection
