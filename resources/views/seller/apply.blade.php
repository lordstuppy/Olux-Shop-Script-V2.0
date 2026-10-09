@extends('layouts.app')

@section('title', __('Sell with us'))

@section('content')
    <h1>{{ __('Sell digital products') }}</h1>
    <p>{{ __('Sellers can list software tools, tutorials, templates, licence keys and subscriptions they have the right to sell. Every product is reviewed before it appears in the catalog.') }}</p>

    @auth
        @if (auth()->user()->isSeller())
            <p>{{ __('Your seller account is active.') }} <a href="{{ route('seller.dashboard') }}">{{ __('Go to the seller dashboard') }}</a>.</p>
        @elseif ($profile && $profile->status === \App\Enums\SellerProfileStatus::Pending)
            <div class="flash flash-info" role="status">{{ __('Your application from :date is waiting for review.', ['date' => $profile->created_at->format('Y-m-d')]) }}</div>
        @else
            @if ($profile && $profile->status === \App\Enums\SellerProfileStatus::Rejected)
                <div class="flash flash-error" role="alert">{{ __('Your previous application was rejected: :reason. You may apply again.', ['reason' => $profile->review_note]) }}</div>
            @endif
            <form method="post" action="{{ route('seller.apply.store') }}" class="stack">
                @csrf
                <x-field name="display_name" :label="__('Shop name shown to buyers')" :value="$profile?->display_name" maxlength="80" required />
                <x-select name="payout_currency" :label="__('Payout currency')" :options="array_combine($currencies, $currencies)" :value="$profile?->payout_currency" />
                <x-select name="payout_crypto" :label="__('Payout cryptocurrency')" :options="collect($cryptos)->pluck('display_name', 'name')->all()" :value="$profile?->payout_crypto" />
                <x-field name="payout_address" :label="__('Payout address')" :value="$profile?->payout_address" :hint="__('The crypto wallet address where approved payouts are sent.')" maxlength="255" required />
                <x-textarea name="about" :label="__('What will you sell?')" :value="$profile?->about" maxlength="2000" />
                <label class="check">
                    <input type="checkbox" name="accept_seller_terms" value="1" required>
                    <span>{{ __('I accept the') }} <a href="{{ route('pages.terms') }}#sellers">{{ __('seller terms') }}</a>. {{ __('I will not list stolen accounts or credentials, hacking or phishing tools, malware, personal data, or anything I do not have the right to sell.') }}</span>
                </label>
                <button type="submit">{{ __('Submit application') }}</button>
            </form>
        @endif
    @else
        <p><a href="{{ route('register') }}">{{ __('Create an account') }}</a> {{ __('or') }} <a href="{{ route('login') }}">{{ __('sign in') }}</a> {{ __('to apply.') }}</p>
    @endauth
@endsection
