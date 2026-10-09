@extends('layouts.app')

@section('title', 'Payouts')
@section('noindex', true)
@section('subnav') @include('partials.seller-nav') @endsection

@section('content')
    <h1>Payouts</h1>
    <p>Earnings become available {{ $holdDays }} days after a sale. Payouts go to <span class="mono">{{ $profile?->payout_address }}</span>.</p>

    @if ($balances === [])
        <p>No earnings yet.</p>
    @else
        <ul class="stats">
            @foreach ($balances as $currency => $b)
                <li><span>Available ({{ $currency }})</span><strong>{{ money($b['available'], $currency) }}</strong><span class="muted">Total {{ money($b['total'], $currency) }}, on hold {{ money($b['pending'], $currency) }}</span></li>
            @endforeach
        </ul>

        <h2>Request a payout</h2>
        <form method="post" action="{{ route('seller.payouts.store') }}" class="stack" data-once>
            @csrf
            <x-field name="amount" label="Amount" inputmode="decimal" hint="Minimum {{ \App\Support\Money::toDecimal((int) config('shop.min_payout_minor'), array_key_first($balances)) }}" required />
            <x-select name="currency" label="Currency" :options="array_combine(array_keys($balances), array_keys($balances))" />
            <button type="submit">Request payout</button>
        </form>
    @endif

    <h2>History</h2>
    @if ($payouts->isEmpty())
        <p>No payouts requested.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">#</th><th scope="col">Requested</th><th scope="col" class="num">Amount</th><th scope="col">Status</th><th scope="col">Reference</th></tr></thead>
                <tbody>
                    @foreach ($payouts as $payout)
                        <tr>
                            <td>{{ $payout->id }}</td>
                            <td>{{ $payout->created_at->format('Y-m-d') }}</td>
                            <td class="num">{{ money($payout->amount_minor, $payout->currency) }}</td>
                            <td><x-status :value="$payout->status" /> {{ $payout->note }}</td>
                            <td class="mono">{{ $payout->reference }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $payouts->links() }}
    @endif
@endsection
