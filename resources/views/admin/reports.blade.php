@extends('layouts.app')

@section('title', __('Reports - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Sales reports') }}</h1>
    <form class="filters" method="get">
        <x-field name="from" :label="__('From')" type="date" :value="$from->toDateString()" />
        <x-field name="to" :label="__('To')" type="date" :value="$to->toDateString()" />
        <button type="submit">{{ __('Show') }}</button>
    </form>

    <h2>{{ __('Summary') }}</h2>
    <ul class="stats">
        <li><span>{{ __('Orders created') }}</span><strong>{{ $conversion['created'] }}</strong></li>
        <li><span>{{ __('Of those paid') }}</span><strong>{{ $conversion['paid'] }}</strong><span class="muted">{{ $conversion['rate'] === null ? __('n/a') : __(':rate% conversion', ['rate' => number_format($conversion['rate'] * 100, 1)]) }}</span></li>
        @foreach ($totals as $t)
            <li><span>{{ __('Net revenue (:currency)', ['currency' => $t->currency]) }}</span><strong>{{ money((int) $t->gross - (int) $t->refunded, $t->currency) }}</strong>
                <span class="muted">{{ __(':count orders; refunds :rate%; discounts :amount', ['count' => $t->orders, 'rate' => (int) $t->gross > 0 ? number_format((int) $t->refunded / (int) $t->gross * 100, 1) : '0.0', 'amount' => money((int) $t->discounts, $t->currency)]) }}</span></li>
        @endforeach
    </ul>

    @forelse ($daily as $currency => $series)
        <h2>{{ __('Net revenue per day (:currency)', ['currency' => $currency]) }}</h2>
        @include('partials.column-chart', ['series' => $series, 'currency' => $currency, 'caption' => __('Net revenue per day in :currency, :from to :to', ['currency' => $currency, 'from' => $from->toDateString(), 'to' => $to->toDateString()])])
        <details>
            <summary>{{ __('Show the data as a table') }}</summary>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">{{ __('Day') }}</th><th scope="col" class="num">{{ __('Net revenue') }}</th></tr></thead>
                    <tbody>
                        @foreach ($series as $point)
                            <tr><td>{{ $point['date'] }}</td><td class="num">{{ money($point['minor'], $currency) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @empty
        <p>{{ __('No paid orders in this period.') }}</p>
    @endforelse

    <h2>{{ __('Top products') }}</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Product') }}</th><th scope="col" class="num">{{ __('Units') }}</th><th scope="col" class="num">{{ __('Net revenue') }}</th></tr></thead>
            <tbody>
                @forelse ($products as $p)
                    <tr><td>{{ $p->title }}</td><td class="num">{{ $p->units }}</td><td class="num">{{ money((int) $p->net, $p->currency) }}</td></tr>
                @empty
                    <tr><td colspan="3">{{ __('No sales.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>{{ __('Top sellers') }}</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Seller') }}</th><th scope="col" class="num">{{ __('Orders') }}</th><th scope="col" class="num">{{ __('Net sales') }}</th><th scope="col" class="num">{{ __('Seller earnings') }}</th></tr></thead>
            <tbody>
                @forelse ($sellers as $s)
                    <tr><td>{{ $s->display_name ?? $s->email }}</td><td class="num">{{ $s->orders }}</td><td class="num">{{ money((int) $s->net, $s->currency) }}</td><td class="num">{{ money((int) $s->earnings, $s->currency) }}</td></tr>
                @empty
                    <tr><td colspan="4">{{ __('No sales.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
