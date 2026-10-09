@extends('layouts.app')

@section('title', __('Create account'))

@section('content')
    <h1>{{ __('Create an account') }}</h1>
    <form method="post" action="{{ route('register') }}" class="stack">
        @csrf
        <input type="hidden" name="form_token" value="{{ $formToken }}">
        {{-- Honeypot: hidden from people and assistive technology; bots fill it in. --}}
        <div class="hp" aria-hidden="true">
            <label for="website">{{ __('Leave this field empty') }}</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>
        <x-field name="name" :label="__('Display name')" autocomplete="name" maxlength="80" required />
        <x-field name="email" :label="__('Email')" type="email" autocomplete="email" required />
        <x-field name="password" :label="__('Password')" type="password" autocomplete="new-password" :hint="__('At least 12 characters, including letters and numbers.')" required />
        <x-field name="password_confirmation" :label="__('Repeat password')" type="password" autocomplete="new-password" required />
        <label class="check">
            <input type="checkbox" name="accept_terms" value="1" required>
            <span>{{ __('I accept the') }} <a href="{{ route('pages.terms') }}">{{ __('terms of service') }}</a> {{ __('and have read the') }} <a href="{{ route('pages.privacy') }}">{{ __('privacy policy') }}</a>.</span>
        </label>
        <button type="submit">{{ __('Create account') }}</button>
    </form>
    <p class="mt">{{ __('Already registered?') }} <a href="{{ route('login') }}">{{ __('Sign in') }}</a>.</p>
@endsection
