@extends('layouts.app')

@section('title', __('Choose a new password'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Choose a new password') }}</h1>
    <form method="post" action="{{ route('password.update') }}" class="stack">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-field name="email" :label="__('Email')" type="email" :value="$email" autocomplete="email" required />
        <x-field name="password" :label="__('New password')" type="password" autocomplete="new-password" :hint="__('At least 12 characters, including letters and numbers.')" required />
        <x-field name="password_confirmation" :label="__('Repeat new password')" type="password" autocomplete="new-password" required />
        <button type="submit">{{ __('Change password') }}</button>
    </form>
@endsection
