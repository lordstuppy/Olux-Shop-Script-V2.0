@extends('layouts.app')

@section('title', __('Payout settings'))
@section('noindex', true)
@section('subnav') @include('partials.seller-nav') @endsection

@section('content')
    <h1>{{ __('Payout settings') }}</h1>
    <p>{{ __('Current payout address:') }} <span class="mono">{{ $profile->payout_address }}</span> ({{ $profile->payout_crypto ?? __('cryptocurrency not set') }}).</p>
    @if ($until = $profile->payoutsBlockedUntil())
        <div class="flash flash-info" role="status">{{ __('The address changed recently. Payouts are paused until :time UTC.', ['time' => $until->format('Y-m-d H:i')]) }}</div>
    @endif
    @if ($profile->pending_payout_address && $profile->payout_change_expires_at?->isFuture())
        <div class="flash flash-info" role="status">{{ __('A change to') }} <span class="mono">{{ $profile->pending_payout_address }}</span> {{ __('is waiting for confirmation from your email.') }}</div>
    @endif

    <h2>{{ __('Change payout address') }}</h2>
    <p>{{ __('For your security we ask for your password, send a confirmation link to your email, and pause payouts for :hours hours after the change.', ['hours' => $cooldownHours]) }}</p>
    <form method="post" action="{{ route('seller.payout-settings.store') }}" class="stack">
        @csrf
        <x-select name="payout_crypto" :label="__('Payout cryptocurrency')" :options="collect($cryptos)->pluck('display_name', 'name')->all()" :value="$profile->payout_crypto" />
        <x-field name="payout_address" :label="__('New payout address')" maxlength="255" autocomplete="off" required />
        <button type="submit">{{ __('Request change') }}</button>
    </form>
@endsection
