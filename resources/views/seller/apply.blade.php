@extends('layouts.app')

@section('title', 'Sell with us')

@section('content')
    <h1>Sell digital products</h1>
    <p>Sellers can list software tools, tutorials, templates, licence keys and subscriptions they have the right to sell. Every product is reviewed before it appears in the catalog.</p>

    @auth
        @if (auth()->user()->isSeller())
            <p>Your seller account is active. <a href="{{ route('seller.dashboard') }}">Go to the seller dashboard</a>.</p>
        @elseif ($profile && $profile->status === \App\Enums\SellerProfileStatus::Pending)
            <div class="flash flash-info" role="status">Your application from {{ $profile->created_at->format('Y-m-d') }} is waiting for review.</div>
        @else
            @if ($profile && $profile->status === \App\Enums\SellerProfileStatus::Rejected)
                <div class="flash flash-error" role="alert">Your previous application was rejected: {{ $profile->review_note }}. You may apply again.</div>
            @endif
            <form method="post" action="{{ route('seller.apply.store') }}" class="stack">
                @csrf
                <x-field name="display_name" label="Shop name shown to buyers" :value="$profile?->display_name" maxlength="80" required />
                <x-select name="payout_currency" label="Payout currency" :options="array_combine($currencies, $currencies)" :value="$profile?->payout_currency" />
                <x-field name="payout_address" label="Payout address" :value="$profile?->payout_address" hint="The crypto wallet address where approved payouts are sent." maxlength="255" required />
                <x-textarea name="about" label="What will you sell?" :value="$profile?->about" maxlength="2000" />
                <label class="check">
                    <input type="checkbox" name="accept_seller_terms" value="1" required>
                    <span>I accept the <a href="{{ route('pages.terms') }}#sellers">seller terms</a>. I will not list stolen accounts or credentials, hacking or phishing tools, malware, personal data, or anything I do not have the right to sell.</span>
                </label>
                <button type="submit">Submit application</button>
            </form>
        @endif
    @else
        <p><a href="{{ route('register') }}">Create an account</a> or <a href="{{ route('login') }}">sign in</a> to apply.</p>
    @endauth
@endsection
