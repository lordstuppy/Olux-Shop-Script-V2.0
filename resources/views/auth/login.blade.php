@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
    <h1>Sign in</h1>
    <form method="post" action="{{ route('login') }}" class="stack">
        @csrf
        <x-field name="email" label="Email" type="email" autocomplete="email" required />
        <x-field name="password" label="Password" type="password" autocomplete="current-password" required />
        <label class="check"><input type="checkbox" name="remember" value="1"> Keep me signed in on this device</label>
        <button type="submit">Sign in</button>
    </form>
    <p class="mt"><a href="{{ route('password.request') }}">Forgot your password?</a></p>
    <p>No account yet? <a href="{{ route('register') }}">Create one</a>.</p>
@endsection
