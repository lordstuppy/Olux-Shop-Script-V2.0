@extends('layouts.app')

@section('title', __('Exchange rates - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Exchange rates') }}</h1>
    <p>{{ __('Rates are set per direction and are never inverted automatically. A product listed in one currency can only be bought in another when a rate for that direction exists. Orders keep the rate used at checkout.') }} {{ __('See') }} <span class="mono">docs/CURRENCY_POLICY.md</span>.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Pair') }}</th><th scope="col">{{ __('Rate') }}</th><th scope="col">{{ __('Source') }}</th><th scope="col">{{ __('Updated') }}</th></tr></thead>
            <tbody>
                @forelse ($rates as $rate)
                    <tr><td>1 {{ $rate->base }} =</td><td class="mono">{{ $rate->rate }} {{ $rate->quote }}</td><td>{{ $rate->source }}</td><td>{{ $rate->updated_at->format('Y-m-d H:i') }}</td></tr>
                @empty
                    <tr><td colspan="4">{{ __('No rates configured; only same-currency purchases are possible.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>{{ __('Set a rate') }}</h2>
    <form method="post" action="{{ route('admin.rates.store') }}" class="stack">
        @csrf
        <x-select name="base" :label="__('From (base)')" :options="array_combine($currencies, $currencies)" />
        <x-select name="quote" :label="__('To (quote)')" :options="array_combine($currencies, $currencies)" />
        <x-field name="rate" :label="__('Rate')" inputmode="decimal" :hint="__('Units of the quote currency for one unit of the base currency, e.g. 0.9215.')" required />
        <button type="submit">{{ __('Save rate') }}</button>
    </form>
@endsection
