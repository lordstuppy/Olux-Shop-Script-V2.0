@extends('layouts.app')

@section('title', 'Create account')

@section('content')
    <h1>Create an account</h1>
    <form method="post" action="{{ route('register') }}" class="stack">
        @csrf
        <input type="hidden" name="form_token" value="{{ $formToken }}">
        {{-- Honeypot: hidden from people and assistive technology; bots fill it in. --}}
        <div class="hp" aria-hidden="true">
            <label for="website">Leave this field empty</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>
        <x-field name="name" label="Display name" autocomplete="name" maxlength="80" required />
        <x-field name="email" label="Email" type="email" autocomplete="email" required />
        <x-field name="password" label="Password" type="password" autocomplete="new-password" hint="At least 12 characters, including letters and numbers." required />
        <x-field name="password_confirmation" label="Repeat password" type="password" autocomplete="new-password" required />
        <label class="check">
            <input type="checkbox" name="accept_terms" value="1" required>
            <span>I accept the <a href="{{ route('pages.terms') }}">terms of service</a> and have read the <a href="{{ route('pages.privacy') }}">privacy policy</a>.</span>
        </label>
        <button type="submit">Create account</button>
    </form>
    <p class="mt">Already registered? <a href="{{ route('login') }}">Sign in</a>.</p>
@endsection
