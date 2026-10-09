@extends('layouts.app')

@section('title', 'Two-factor authentication')
@section('noindex', true)

@section('content')
    <h1>Two-factor authentication</h1>
    <p>Enter the 6-digit code from your authenticator app. If you lost your device, enter one of your recovery codes instead.</p>
    <form method="post" action="{{ route('two-factor.challenge') }}" class="stack">
        @csrf
        <x-field name="code" label="Authentication or recovery code" inputmode="text" autocomplete="one-time-code" maxlength="20" required />
        <button type="submit">Verify and sign in</button>
    </form>
@endsection
