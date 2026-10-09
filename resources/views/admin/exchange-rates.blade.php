@extends('layouts.app')

@section('title', 'Exchange rates - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Exchange rates</h1>
    <p>Rates are set per direction and are never inverted automatically. A product listed in one currency can only be bought in another when a rate for that direction exists. Orders keep the rate used at checkout. See <span class="mono">docs/CURRENCY_POLICY.md</span>.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Pair</th><th scope="col">Rate</th><th scope="col">Source</th><th scope="col">Updated</th></tr></thead>
            <tbody>
                @forelse ($rates as $rate)
                    <tr><td>1 {{ $rate->base }} =</td><td class="mono">{{ $rate->rate }} {{ $rate->quote }}</td><td>{{ $rate->source }}</td><td>{{ $rate->updated_at->format('Y-m-d H:i') }}</td></tr>
                @empty
                    <tr><td colspan="4">No rates configured; only same-currency purchases are possible.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>Set a rate</h2>
    <form method="post" action="{{ route('admin.rates.store') }}" class="stack">
        @csrf
        <x-select name="base" label="From (base)" :options="array_combine($currencies, $currencies)" />
        <x-select name="quote" label="To (quote)" :options="array_combine($currencies, $currencies)" />
        <x-field name="rate" label="Rate" inputmode="decimal" hint="Units of the quote currency for one unit of the base currency, e.g. 0.9215." required />
        <button type="submit">Save rate</button>
    </form>
@endsection
