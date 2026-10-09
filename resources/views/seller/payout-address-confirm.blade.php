@extends('layouts.app')

@section('title', 'Confirm payout address')
@section('noindex', true)

@section('content')
    <h1>Confirm your new payout address</h1>
    <p>Future payouts will go to <span class="mono">{{ $profile->pending_payout_address }}</span> ({{ $profile->pending_payout_crypto }}). Check every character before confirming.</p>
    <form method="post" action="{{ route('seller.payout-address.confirm', $token) }}">
        @csrf
        <button type="submit">Confirm new payout address</button>
    </form>
    <p class="mt"><a href="{{ route('seller.payout-settings') }}">This was not me</a></p>
@endsection
