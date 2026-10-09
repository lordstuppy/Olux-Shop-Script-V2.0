@extends('layouts.app')

@section('title', 'Account settings')
@section('noindex', true)

@section('content')
    <h1>Account settings</h1>
    <p>Signed in as <strong>{{ $user->email }}</strong> ({{ $user->role->value }}).</p>

    <h2>Profile</h2>
    <form method="post" action="{{ route('account.profile') }}" class="stack">
        @csrf
        @method('PUT')
        <x-field name="name" label="Display name" :value="$user->name" maxlength="80" required />
        <button type="submit">Save profile</button>
    </form>

    <h2>Change password</h2>
    <form method="post" action="{{ route('account.password') }}" class="stack">
        @csrf
        @method('PUT')
        <x-field name="current_password" label="Current password" type="password" autocomplete="current-password" required />
        <x-field name="password" label="New password" type="password" autocomplete="new-password" hint="At least 12 characters, including letters and numbers." required />
        <x-field name="password_confirmation" label="Repeat new password" type="password" autocomplete="new-password" required />
        <button type="submit">Change password</button>
    </form>

    @unless ($user->isSeller() || $user->isAdmin())
        <h2>Selling</h2>
        <p><a href="{{ route('seller.apply') }}">Apply to sell your own digital products</a>.</p>
    @endunless
@endsection
