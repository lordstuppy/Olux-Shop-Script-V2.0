@extends('layouts.app')

@section('title', __('Two-factor authentication'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Two-factor authentication') }}</h1>

    @if ($recoveryCodes)
        <section class="card" aria-labelledby="codes-heading">
            <h2 id="codes-heading">{{ __('Your recovery codes') }}</h2>
            <p>{{ __('Each code works once if you lose your authenticator. Store them in a password manager or print them. They will not be shown again.') }}</p>
            <ul class="mono">
                @foreach ($recoveryCodes as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($user->hasTwoFactor())
        <p>{{ __('Two-factor authentication is') }} <strong>{{ __('on') }}</strong> {{ __('since :date.', ['date' => $user->two_factor_confirmed_at->format('Y-m-d')]) }} {{ trans_choice('{1} :count unused recovery code left.|[0,*] :count unused recovery codes left.', $remaining) }}</p>

        <h2>{{ __('Recovery codes') }}</h2>
        <form method="post" action="{{ route('account.two-factor.recovery') }}">
            @csrf
            <button type="submit" class="btn-secondary">{{ __('Create new recovery codes') }}</button>
        </form>

        @unless ($user->isStaff())
            <h2>{{ __('Turn off') }}</h2>
            <form method="post" action="{{ route('account.two-factor.disable') }}" class="stack">
                @csrf
                @method('DELETE')
                <x-field name="code" :label="__('Current authentication or recovery code')" autocomplete="one-time-code" maxlength="20" required />
                <button type="submit" class="btn-danger">{{ __('Turn off two-factor authentication') }}</button>
            </form>
        @else
            <p class="hint">{{ __('Staff accounts must keep two-factor authentication on.') }}</p>
        @endunless
    @else
        <p>{{ __('Protect your account with a second step at sign-in: a 6-digit code from an authenticator app (for example Aegis, 2FAS, Google Authenticator or a password manager).') }}</p>
        <ol>
            <li>{{ __('Scan this QR code with your app, or type the key below.') }}</li>
            <li>{{ __('Enter the 6-digit code the app shows.') }}</li>
        </ol>
        <img class="qr" src="{{ $qr }}" alt="{{ __('QR code to add this account to an authenticator app') }}">
        <p>{{ __('Setup key:') }} <span class="mono">{{ trim(chunk_split($secret, 4, ' ')) }}</span></p>
        <form method="post" action="{{ route('account.two-factor.enable') }}" class="stack">
            @csrf
            <x-field name="code" :label="__('6-digit code from your app')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
            <button type="submit">{{ __('Turn on two-factor authentication') }}</button>
        </form>
    @endif

    <p class="mt"><a href="{{ route('account.settings') }}">{{ __('Back to account settings') }}</a></p>
@endsection
