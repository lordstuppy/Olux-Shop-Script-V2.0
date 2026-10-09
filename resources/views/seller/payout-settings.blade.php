@extends('layouts.app')

@section('title', 'Payout settings')
@section('noindex', true)
@section('subnav') @include('partials.seller-nav') @endsection

@section('content')
    <h1>Payout settings</h1>
    <p>Current payout address: <span class="mono">{{ $profile->payout_address }}</span> ({{ $profile->payout_crypto ?? 'cryptocurrency not set' }}).</p>
    @if ($until = $profile->payoutsBlockedUntil())
        <div class="flash flash-info" role="status">The address changed recently. Payouts are paused until {{ $until->format('Y-m-d H:i') }} UTC.</div>
    @endif
    @if ($profile->pending_payout_address && $profile->payout_change_expires_at?->isFuture())
        <div class="flash flash-info" role="status">A change to <span class="mono">{{ $profile->pending_payout_address }}</span> is waiting for confirmation from your email.</div>
    @endif

    <h2>Change payout address</h2>
    <p>For your security we ask for your password, send a confirmation link to your email, and pause payouts for {{ $cooldownHours }} hours after the change.</p>
    <form method="post" action="{{ route('seller.payout-settings.store') }}" class="stack">
        @csrf
        <x-select name="payout_crypto" label="Payout cryptocurrency" :options="collect($cryptos)->pluck('display_name', 'name')->all()" :value="$profile->payout_crypto" />
        <x-field name="payout_address" label="New payout address" maxlength="255" autocomplete="off" required />
        <button type="submit">Request change</button>
    </form>
@endsection
