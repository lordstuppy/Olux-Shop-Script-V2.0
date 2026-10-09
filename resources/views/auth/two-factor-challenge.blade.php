@extends('layouts.app')

@section('title', __('Two-factor authentication'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Two-factor authentication') }}</h1>
    <p>{{ __('Enter the 6-digit code from your authenticator app. If you lost your device, enter one of your recovery codes instead.') }}</p>
    <form method="post" action="{{ route('two-factor.challenge') }}" class="stack">
        @csrf
        <x-field name="code" :label="__('Authentication or recovery code')" inputmode="text" autocomplete="one-time-code" maxlength="20" required />
        <button type="submit">{{ __('Verify and sign in') }}</button>
    </form>
@endsection
