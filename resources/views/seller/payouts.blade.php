@extends('layouts.app')

@section('title', __('Payouts'))
@section('noindex', true)
@section('subnav') @include('partials.seller-nav') @endsection

@section('content')
    <h1>{{ __('Payouts') }}</h1>
    <p>{{ __('Earnings become available :days days after a sale.', ['days' => $holdDays]) }} {{ __('Payouts go to') }} <span class="mono">{{ $profile?->payout_address }}</span>.</p>

    @if ($balances === [])
        <p>{{ __('No earnings yet.') }}</p>
    @else
        <ul class="stats">
            @foreach ($balances as $currency => $b)
                <li><span>{{ __('Available (:currency)', ['currency' => $currency]) }}</span><strong>{{ money($b['available'], $currency) }}</strong><span class="muted">{{ __('Total :total, on hold :pending', ['total' => money($b['total'], $currency), 'pending' => money($b['pending'], $currency)]) }}@if (($b['disputed'] ?? 0) > 0) {{ __(', :amount held for open disputes', ['amount' => money($b['disputed'], $currency)]) }}@endif</span></li>
            @endforeach
        </ul>

        <h2>{{ __('Request a payout') }}</h2>
        <form method="post" action="{{ route('seller.payouts.store') }}" class="stack" data-once>
            @csrf
            <x-field name="amount" :label="__('Amount')" inputmode="decimal" :hint="__('Minimum :amount', ['amount' => \App\Support\Money::toDecimal((int) config('shop.min_payout_minor'), array_key_first($balances))])" required />
            <x-select name="currency" :label="__('Currency')" :options="array_combine(array_keys($balances), array_keys($balances))" />
            <button type="submit">{{ __('Request payout') }}</button>
        </form>
    @endif

    <h2>{{ __('History') }}</h2>
    @if ($payouts->isEmpty())
        <p>{{ __('No payouts requested.') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">#</th><th scope="col">{{ __('Requested') }}</th><th scope="col" class="num">{{ __('Amount') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Reference') }}</th></tr></thead>
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
