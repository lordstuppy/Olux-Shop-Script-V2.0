@extends('layouts.app')

@section('title', 'Choose a new password')
@section('noindex', true)

@section('content')
    <h1>Choose a new password</h1>
    <form method="post" action="{{ route('password.update') }}" class="stack">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-field name="email" label="Email" type="email" :value="$email" autocomplete="email" required />
        <x-field name="password" label="New password" type="password" autocomplete="new-password" hint="At least 12 characters, including letters and numbers." required />
        <x-field name="password_confirmation" label="Repeat new password" type="password" autocomplete="new-password" required />
        <button type="submit">Change password</button>
    </form>
@endsection
