@extends('layouts.app')

@section('title', __('Confirm your email'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Confirm your email address') }}</h1>
    <p>{{ __('We sent a confirmation link to') }} <strong>{{ $email }}</strong>. {{ __('Open it to finish setting up your account. You need a confirmed address to check out, redeem gift cards or apply to sell.') }}</p>
    <form method="post" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn-secondary">{{ __('Send the link again') }}</button>
    </form>
    <p class="mt">{{ __('Wrong address?') }} <a href="{{ route('account.settings') }}">{{ __('Change it in your account settings') }}</a>.</p>
@endsection
