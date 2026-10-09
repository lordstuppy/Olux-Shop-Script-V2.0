@extends('layouts.app')

@section('title', 'Reset password')

@section('content')
    <h1>Reset your password</h1>
    <p>Enter the email address of your account. We will send a link to choose a new password.</p>
    <form method="post" action="{{ route('password.email') }}" class="stack">
        @csrf
        <x-field name="email" label="Email" type="email" autocomplete="email" required />
        <button type="submit">Send reset link</button>
    </form>
@endsection
