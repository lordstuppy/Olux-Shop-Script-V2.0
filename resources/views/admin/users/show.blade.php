@extends('layouts.app')

@section('title', __(':email - Admin', ['email' => $user->email]))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ $user->email }}</h1>
    <p>{{ $user->name }} &middot; {{ $user->role->label() }} &middot; <x-status :value="$user->status" /> &middot; {{ __('Balance :amount', ['amount' => money($user->balance_minor, $user->currency)]) }} &middot; {{ __('Last login :time', ['time' => $user->last_login_at?->format('Y-m-d H:i') ?? __('never')]) }}</p>

    <p>{{ $user->hasVerifiedEmail() ? __('Email confirmed') : __('Email not confirmed') }} &middot; {{ $user->hasTwoFactor() ? __('Two-factor on') : __('Two-factor off') }} &middot; {{ trans_choice('{1} :count active session|[0,*] :count active sessions', $sessionCount) }}</p>

    <div class="actions">
        @can('sessions.revoke')
            <form method="post" action="{{ route('admin.users.sessions.revoke', $user) }}">
                @csrf
                <button type="submit" class="btn-secondary">{{ __('Sign out all sessions') }}</button>
            </form>
        @endcan
    </div>

    @can('users.manage')
    <div class="actions mt">
        <form method="post" action="{{ route('admin.users.role', $user) }}" class="actions">
            @csrf
            <x-select name="role" :label="__('Role')" :options="collect(\App\Enums\UserRole::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()" :value="$user->role->value" :hint="__('Support and finance are staff roles with limited admin access.')" />
            <button type="submit" class="btn-secondary">{{ __('Change role') }}</button>
        </form>
        <form method="post" action="{{ route('admin.users.status', $user) }}">
            @csrf
            @if ($user->isActive())
                <input type="hidden" name="status" value="suspended">
                <button type="submit" class="btn-danger">{{ __('Suspend account') }}</button>
            @else
                <input type="hidden" name="status" value="active">
                <button type="submit">{{ __('Reactivate account') }}</button>
            @endif
        </form>
    </div>
    @endcan

    @can('balances.adjust')
        <h2>{{ __('Adjust balance') }}</h2>
        <form method="post" action="{{ route('admin.users.balance', $user) }}" class="stack">
            @csrf
            <x-select name="direction" :label="__('Direction')" :options="['credit' => __('Credit (add)'), 'debit' => __('Debit (remove)')]" />
            <x-field name="amount" :label="__('Amount')" inputmode="decimal" required />
            <x-select name="currency" :label="__('Currency')" :options="array_combine(\App\Support\Money::supported(), \App\Support\Money::supported())" :value="$user->currency" />
            <x-field name="reason" :label="__('Reason (shown in the user\'s wallet history)')" maxlength="255" required />
            <button type="submit">{{ __('Apply adjustment') }}</button>
        </form>
    @endcan

    @if ($user->sellerProfile)
        <h2>{{ __('Seller profile') }}</h2>
        <p>{{ $user->sellerProfile->display_name }} &middot; <x-status :value="$user->sellerProfile->status" /> &middot; {{ __('payout :currency to', ['currency' => $user->sellerProfile->payout_currency]) }} <span class="mono">{{ $user->sellerProfile->payout_address }}</span></p>
    @endif

    <h2>{{ __('Recent orders') }}</h2>
    @include('admin.orders.table', ['orders' => $orders->each(fn ($o) => $o->setRelation('buyer', $user))])

    <h2>{{ __('Balance history') }}</h2>
    <ul>
        @forelse ($transactions as $tx)
            <li>{{ $tx->created_at->format('Y-m-d H:i') }}: {{ $tx->type }} {{ money($tx->amount_minor < 0 ? -$tx->amount_minor : $tx->amount_minor, $tx->currency) }} {{ $tx->amount_minor < 0 ? __('debit') : __('credit') }}, {{ __('balance :amount', ['amount' => money($tx->balance_after_minor, $tx->currency)]) }}</li>
        @empty
            <li>{{ __('No balance activity.') }}</li>
        @endforelse
    </ul>
@endsection
