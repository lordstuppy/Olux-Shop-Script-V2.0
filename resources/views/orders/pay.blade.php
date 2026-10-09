@extends('layouts.app')

@section('title', __('Pay order :order', ['order' => $order->shortId()]))
@section('noindex', true)

@if ($payment?->wallet_address && ! $stale)
    @push('head')
        {{-- Refreshes the status without JavaScript. --}}
        <meta http-equiv="refresh" content="30;url={{ route('orders.result', $order) }}">
    @endpush
@endif

@section('content')
    <h1>{{ __('Pay order') }} <span class="mono">{{ $order->shortId() }}</span></h1>
    <p>{{ __('Amount due:') }} <strong>{{ money($order->total_minor, $order->currency) }}</strong>. {{ __('Unpaid orders expire at :time UTC.', ['time' => $order->expires_at?->format('Y-m-d H:i')]) }}</p>

    @if ($payment?->wallet_address && $stale)
        <section class="card" aria-labelledby="stale-heading">
            <h2 id="stale-heading">{{ __('Refresh the amount before paying') }}</h2>
            <p>{{ __('The :crypto amount was calculated at :time UTC. Exchange rates move, so get a current amount before you send anything.', ['crypto' => $payment->crypto, 'time' => $payment->quoted_at?->format('H:i') ?? __('an earlier time')]) }}</p>
            <form method="post" action="{{ route('orders.pay.start', $order) }}" data-once>
                @csrf
                <input type="hidden" name="crypto" value="{{ $payment->crypto }}">
                <button type="submit">{{ __('Get current :crypto amount', ['crypto' => $payment->crypto]) }}</button>
            </form>
        </section>
    @elseif ($payment?->wallet_address)
        <div class="two-col">
            <section class="card" aria-labelledby="send-heading">
                <h2 id="send-heading">{{ __('Send :crypto', ['crypto' => $payment->crypto]) }}</h2>
                <p>{{ __('Send exactly this amount:') }}</p>
                <p class="price mono">{{ $payment->crypto_amount }} {{ $payment->crypto }}</p>
                <p>{{ __('To this address:') }}</p>
                <p class="mono">{{ $payment->wallet_address }}</p>
                @if ($qr)
                    <img class="qr" src="{{ $qr }}" alt="{{ __('QR code containing the wallet address :address', ['address' => $payment->wallet_address]) }}">
                @endif
                <p class="hint">{{ __('This amount is valid until :time UTC. Payments are confirmed by our payment server, not by this page. After the network confirms your transaction, your order will be marked paid and you will receive an email.', ['time' => $payment->quoted_at->copy()->addMinutes((int) config('shop.quote_ttl_minutes'))->format('H:i')]) }}</p>
                <p><a class="btn" href="{{ route('orders.result', $order) }}">{{ __('I have sent the payment - check status') }}</a></p>
            </section>

            <section aria-labelledby="switch-heading">
                <h2 id="switch-heading">{{ __('Use another cryptocurrency') }}</h2>
                <form method="post" action="{{ route('orders.pay.start', $order) }}" class="stack">
                    @csrf
                    <x-select name="crypto" :label="__('Cryptocurrency')" :options="collect($cryptos)->pluck('display_name', 'name')->all()" :value="$payment->crypto" />
                    <button type="submit" class="btn-secondary">{{ __('Create new invoice') }}</button>
                </form>
            </section>
        </div>
    @else
        <section class="card" aria-labelledby="choose-heading">
            <h2 id="choose-heading">{{ __('Choose how to pay') }}</h2>
            <form method="post" action="{{ route('orders.pay.start', $order) }}" class="stack" data-once>
                @csrf
                <x-select name="crypto" :label="__('Cryptocurrency')" :options="collect($cryptos)->pluck('display_name', 'name')->all()" />
                <button type="submit">{{ __('Create crypto invoice') }}</button>
            </form>
        </section>
    @endif

    @if ($user->currency === $order->currency && $user->balance_minor >= $order->total_minor)
        <h2>{{ __('Pay from your balance') }}</h2>
        <form method="post" action="{{ route('orders.pay.balance', $order) }}" data-once>
            @csrf
            <button type="submit" class="btn-secondary">{{ __('Pay :amount from balance (:balance available)', ['amount' => money($order->total_minor, $order->currency), 'balance' => money($user->balance_minor, $user->currency)]) }}</button>
        </form>
    @endif
@endsection
