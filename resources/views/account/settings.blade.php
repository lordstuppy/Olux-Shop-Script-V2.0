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

    <h2>Two-factor authentication</h2>
    <p>{{ $user->hasTwoFactor() ? 'On.' : 'Off.' }} <a href="{{ route('account.two-factor') }}">{{ $user->hasTwoFactor() ? 'Manage two-factor authentication' : 'Turn on two-factor authentication' }}</a></p>

    <h2>Signed-in sessions</h2>
    @if ($sessions->isEmpty())
        <p>Session details are not available.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">Browser</th><th scope="col">IP address</th><th scope="col">Last active</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
                <tbody>
                    @foreach ($sessions as $session)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::limit((string) $session->user_agent, 80) }}</td>
                            <td class="mono">{{ $session->ip_address }}</td>
                            <td>{{ \Illuminate\Support\Carbon::createFromTimestamp($session->last_activity)->format('Y-m-d H:i') }} UTC</td>
                            <td>
                                @if ($session->current)
                                    This device
                                @else
                                    <form method="post" action="{{ route('account.sessions.destroy', $session->handle) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-link">Sign out<span class="visually-hidden"> session from {{ $session->ip_address }}</span></button>
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
            <button type="submit" class="btn-secondary">Sign out all other sessions</button>
        </form>
    @endif

    @unless ($user->isSeller() || $user->isStaff())
        <h2>Selling</h2>
        <p><a href="{{ route('seller.apply') }}">Apply to sell your own digital products</a>.</p>
    @endunless
@endsection
