@extends('layouts.app')

@section('title', 'Confirm your email')
@section('noindex', true)

@section('content')
    <h1>Confirm your email address</h1>
    <p>We sent a confirmation link to <strong>{{ $email }}</strong>. Open it to finish setting up your account. You need a confirmed address to check out, redeem gift cards or apply to sell.</p>
    <form method="post" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn-secondary">Send the link again</button>
    </form>
    <p class="mt">Wrong address? <a href="{{ route('account.settings') }}">Change it in your account settings</a>.</p>
@endsection
