@extends('layouts.app')

@section('title', 'Confirm password')
@section('noindex', true)

@section('content')
    <h1>Confirm your password</h1>
    <p>This action is sensitive. Enter your password to continue; you will not be asked again for {{ (int) (config('auth.password_timeout') / 60) }} minutes.</p>
    <form method="post" action="{{ route('password.confirm') }}" class="stack">
        @csrf
        <x-field name="password" label="Password" type="password" autocomplete="current-password" required />
        <button type="submit">Confirm</button>
    </form>
@endsection
