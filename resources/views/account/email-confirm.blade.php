@extends('layouts.app')

@section('title', __('Confirm email change'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Confirm your new email address') }}</h1>
    <p>{{ __('Your account email will change to') }} <strong>{{ $pending }}</strong>.</p>
    <form method="post" action="{{ route('account.email.confirm', $token) }}">
        @csrf
        <button type="submit">{{ __('Confirm email change') }}</button>
    </form>
@endsection
