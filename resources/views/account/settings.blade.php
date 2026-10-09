@extends('layouts.app')

@section('title', __('Account settings'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Account settings') }}</h1>
    <p>{{ __('Signed in as') }} <strong>{{ $user->email }}</strong> ({{ $user->role->label() }}).</p>

    <h2>{{ __('Profile') }}</h2>
    <form method="post" action="{{ route('account.profile') }}" class="stack">
        @csrf
        @method('PUT')
        <x-field name="name" :label="__('Display name')" :value="$user->name" maxlength="80" required />
        <button type="submit">{{ __('Save profile') }}</button>
    </form>

    <h2>{{ __('Email address') }}</h2>
    @if ($user->pending_email && $user->email_change_expires_at?->isFuture())
        <p class="hint">{{ __('A change to :email is waiting for confirmation from that address.', ['email' => $user->pending_email]) }}</p>
    @endif
    <form method="post" action="{{ route('account.email') }}" class="stack">
        @csrf
        <x-field name="email" id="new-email" :label="__('New email address')" type="email" autocomplete="email" required />
        <x-field name="current_password" id="email-current-password" :label="__('Current password')" type="password" autocomplete="current-password" required />
        <button type="submit">{{ __('Send confirmation link') }}</button>
    </form>

    <h2>{{ __('Change password') }}</h2>
    <form method="post" action="{{ route('account.password') }}" class="stack">
        @csrf
        @method('PUT')
        <x-field name="current_password" :label="__('Current password')" type="password" autocomplete="current-password" required />
        <x-field name="password" :label="__('New password')" type="password" autocomplete="new-password" :hint="__('At least 12 characters, including letters and numbers.')" required />
        <x-field name="password_confirmation" :label="__('Repeat new password')" type="password" autocomplete="new-password" required />
        <button type="submit">{{ __('Change password') }}</button>
    </form>

    <h2>{{ __('Two-factor authentication') }}</h2>
    <p>{{ $user->hasTwoFactor() ? __('On.') : __('Off.') }} <a href="{{ route('account.two-factor') }}">{{ $user->hasTwoFactor() ? __('Manage two-factor authentication') : __('Turn on two-factor authentication') }}</a></p>

    <h2>{{ __('Signed-in sessions') }}</h2>
    @if ($sessions->isEmpty())
        <p>{{ __('Session details are not available.') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">{{ __('Browser') }}</th><th scope="col">{{ __('IP address') }}</th><th scope="col">{{ __('Last active') }}</th><th scope="col"><span class="visually-hidden">{{ __('Action') }}</span></th></tr></thead>
                <tbody>
                    @foreach ($sessions as $session)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::limit((string) $session->user_agent, 80) }}</td>
                            <td class="mono">{{ $session->ip_address }}</td>
                            <td>{{ \Illuminate\Support\Carbon::createFromTimestamp($session->last_activity)->format('Y-m-d H:i') }} UTC</td>
                            <td>
                                @if ($session->current)
                                    {{ __('This device') }}
                                @else
                                    <form method="post" action="{{ route('account.sessions.destroy', $session->handle) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-link">{{ __('Sign out') }}<span class="visually-hidden"> {{ __('session from :ip', ['ip' => $session->ip_address]) }}</span></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <form method="post" action="{{ route('account.sessions.destroy-others') }}" class="mt">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-secondary">{{ __('Sign out all other sessions') }}</button>
        </form>
    @endif

    @unless ($user->isSeller() || $user->isStaff())
        <h2>{{ __('Selling') }}</h2>
        <p><a href="{{ route('seller.apply') }}">{{ __('Apply to sell your own digital products') }}</a>.</p>
    @endunless
@endsection
