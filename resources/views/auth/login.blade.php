@extends('layouts.app')

@section('title', __('Sign in'))

@section('content')
    <h1>{{ __('Sign in') }}</h1>
    <form method="post" action="{{ route('login') }}" class="stack">
        @csrf
        <x-field name="email" :label="__('Email')" type="email" autocomplete="email" required />
        <x-field name="password" :label="__('Password')" type="password" autocomplete="current-password" required />
        <label class="check"><input type="checkbox" name="remember" value="1"> {{ __('Keep me signed in on this device') }}</label>
        <button type="submit">{{ __('Sign in') }}</button>
    </form>
    <p class="mt"><a href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a></p>
    <p>{{ __('No account yet?') }} <a href="{{ route('register') }}">{{ __('Create one') }}</a>.</p>
@endsection
