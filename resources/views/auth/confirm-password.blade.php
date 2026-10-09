@extends('layouts.app')

@section('title', __('Confirm password'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Confirm your password') }}</h1>
    <p>{{ __('This action is sensitive. Enter your password to continue; you will not be asked again for :minutes minutes.', ['minutes' => (int) (config('auth.password_timeout') / 60)]) }}</p>
    <form method="post" action="{{ route('password.confirm') }}" class="stack">
        @csrf
        <x-field name="password" :label="__('Password')" type="password" autocomplete="current-password" required />
        <button type="submit">{{ __('Confirm') }}</button>
    </form>
@endsection
