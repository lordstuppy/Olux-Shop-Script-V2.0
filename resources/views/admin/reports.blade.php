@extends('layouts.app')

@section('title', 'Reports - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Sales reports</h1>
    <form class="filters" method="get">
        <x-field name="from" label="From" type="date" :value="$from->toDateString()" />
        <x-field name="to" label="To" type="date" :value="$to->toDateString()" />
        <button type="submit">Show</button>
    </form>

    <h2>Summary</h2>
    <ul class="stats">
        <li><span>Orders created</span><strong>{{ $conversion['created'] }}</strong></li>
        <li><span>Of those paid</span><strong>{{ $conversion['paid'] }}</strong><span class="muted">{{ $conversion['rate'] === null ? 'n/a' : number_format($conversion['rate'] * 100, 1).'% conversion' }}</span></li>
        @foreach ($totals as $t)
            <li><span>Net revenue ({{ $t->currency }})</span><strong>{{ money((int) $t->gross - (int) $t->refunded, $t->currency) }}</strong>
                <span class="muted">{{ $t->orders }} orders; refunds {{ (int) $t->gross > 0 ? number_format((int) $t->refunded / (int) $t->gross * 100, 1) : '0.0' }}%; discounts {{ money((int) $t->discounts, $t->currency) }}</span></li>
        @endforeach
    </ul>

    @forelse ($daily as $currency => $series)
        <h2>Net revenue per day ({{ $currency }})</h2>
        @include('partials.column-chart', ['series' => $series, 'currency' => $currency, 'caption' => "Net revenue per day in {$currency}, {$from->toDateString()} to {$to->toDateString()}"])
        <details>
            <summary>Show the data as a table</summary>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Day</th><th scope="col" class="num">Net revenue</th></tr></thead>
                    <tbody>
                        @foreach ($series as $point)
                            <tr><td>{{ $point['date'] }}</td><td class="num">{{ money($point['minor'], $currency) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @empty
        <p>No paid orders in this period.</p>
    @endforelse

    <h2>Top products</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Product</th><th scope="col" class="num">Units</th><th scope="col" class="num">Net revenue</th></tr></thead>
            <tbody>
                @forelse ($products as $p)
                    <tr><td>{{ $p->title }}</td><td class="num">{{ $p->units }}</td><td class="num">{{ money((int) $p->net, $p->currency) }}</td></tr>
                @empty
                    <tr><td colspan="3">No sales.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>Top sellers</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Seller</th><th scope="col" class="num">Orders</th><th scope="col" class="num">Net sales</th><th scope="col" class="num">Seller earnings</th></tr></thead>
            <tbody>
                @forelse ($sellers as $s)
                    <tr><td>{{ $s->display_name ?? $s->email }}</td><td class="num">{{ $s->orders }}</td><td class="num">{{ money((int) $s->net, $s->currency) }}</td><td class="num">{{ money((int) $s->earnings, $s->currency) }}</td></tr>
                @empty
                    <tr><td colspan="4">No sales.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
