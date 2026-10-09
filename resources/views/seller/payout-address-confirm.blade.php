@extends('layouts.app')

@section('title', __('Confirm payout address'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Confirm your new payout address') }}</h1>
    <p>{{ __('Future payouts will go to') }} <span class="mono">{{ $profile->pending_payout_address }}</span> ({{ $profile->pending_payout_crypto }}). {{ __('Check every character before confirming.') }}</p>
    <form method="post" action="{{ route('seller.payout-address.confirm', $token) }}">
        @csrf
        <button type="submit">{{ __('Confirm new payout address') }}</button>
    </form>
    <p class="mt"><a href="{{ route('seller.payout-settings') }}">{{ __('This was not me') }}</a></p>
@endsection
