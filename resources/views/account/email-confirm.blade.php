@extends('layouts.app')

@section('title', 'Confirm email change')
@section('noindex', true)

@section('content')
    <h1>Confirm your new email address</h1>
    <p>Your account email will change to <strong>{{ $pending }}</strong>.</p>
    <form method="post" action="{{ route('account.email.confirm', $token) }}">
        @csrf
        <button type="submit">Confirm email change</button>
    </form>
@endsection
