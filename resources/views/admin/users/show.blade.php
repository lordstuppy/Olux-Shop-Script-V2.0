@extends('layouts.app')

@section('title', $user->email.' - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ $user->email }}</h1>
    <p>{{ $user->name }} &middot; {{ $user->role->value }} &middot; <x-status :value="$user->status" /> &middot; Balance {{ money($user->balance_minor, $user->currency) }} &middot; Last login {{ $user->last_login_at?->format('Y-m-d H:i') ?? 'never' }}</p>

    <p>Email {{ $user->hasVerifiedEmail() ? 'confirmed' : 'not confirmed' }} &middot; Two-factor {{ $user->hasTwoFactor() ? 'on' : 'off' }} &middot; {{ $sessionCount }} active {{ $sessionCount === 1 ? 'session' : 'sessions' }}</p>

    <div class="actions">
        @can('sessions.revoke')
            <form method="post" action="{{ route('admin.users.sessions.revoke', $user) }}">
                @csrf
                <button type="submit" class="btn-secondary">Sign out all sessions</button>
            </form>
        @endcan
    </div>

    @can('users.manage')
    <div class="actions mt">
        <form method="post" action="{{ route('admin.users.role', $user) }}" class="actions">
            @csrf
            <x-select name="role" label="Role" :options="collect(\App\Enums\UserRole::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()" :value="$user->role->value" hint="Support and finance are staff roles with limited admin access." />
            <button type="submit" class="btn-secondary">Change role</button>
        </form>
        <form method="post" action="{{ route('admin.users.status', $user) }}">
            @csrf
            @if ($user->isActive())
                <input type="hidden" name="status" value="suspended">
                <button type="submit" class="btn-danger">Suspend account</button>
            @else
                <input type="hidden" name="status" value="active">
                <button type="submit">Reactivate account</button>
            @endif
        </form>
    </div>
    @endcan

    @can('balances.adjust')
        <h2>Adjust balance</h2>
        <form method="post" action="{{ route('admin.users.balance', $user) }}" class="stack">
            @csrf
            <x-select name="direction" label="Direction" :options="['credit' => 'Credit (add)', 'debit' => 'Debit (remove)']" />
            <x-field name="amount" label="Amount" inputmode="decimal" required />
            <x-select name="currency" label="Currency" :options="array_combine(\App\Support\Money::supported(), \App\Support\Money::supported())" :value="$user->currency" />
            <x-field name="reason" label="Reason (shown in the user's wallet history)" maxlength="255" required />
            <button type="submit">Apply adjustment</button>
        </form>
    @endcan

    @if ($user->sellerProfile)
        <h2>Seller profile</h2>
        <p>{{ $user->sellerProfile->display_name }} &middot; <x-status :value="$user->sellerProfile->status" /> &middot; payout {{ $user->sellerProfile->payout_currency }} to <span class="mono">{{ $user->sellerProfile->payout_address }}</span></p>
    @endif

    <h2>Recent orders</h2>
    @include('admin.orders.table', ['orders' => $orders->each(fn ($o) => $o->setRelation('buyer', $user))])

    <h2>Balance history</h2>
    <ul>
        @forelse ($transactions as $tx)
            <li>{{ $tx->created_at->format('Y-m-d H:i') }}: {{ $tx->type }} {{ money($tx->amount_minor < 0 ? -$tx->amount_minor : $tx->amount_minor, $tx->currency) }} {{ $tx->amount_minor < 0 ? 'debit' : 'credit' }}, balance {{ money($tx->balance_after_minor, $tx->currency) }}</li>
        @empty
            <li>No balance activity.</li>
        @endforelse
    </ul>
@endsection
