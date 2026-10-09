@extends('layouts.app')

@section('title', __('Reset password'))

@section('content')
    <h1>{{ __('Reset your password') }}</h1>
    <p>{{ __('Enter the email address of your account. We will send a link to choose a new password.') }}</p>
    <form method="post" action="{{ route('password.email') }}" class="stack">
        @csrf
        <x-field name="email" :label="__('Email')" type="email" autocomplete="email" required />
        <button type="submit">{{ __('Send reset link') }}</button>
    </form>
@endsection
