@extends('layouts.app')

@section('title', 'Pay order '.$order->shortId())
@section('noindex', true)

@if ($payment?->wallet_address)
    @push('head')
        {{-- Refreshes the status without JavaScript. --}}
        <meta http-equiv="refresh" content="30;url={{ route('orders.result', $order) }}">
    @endpush
@endif

@section('content')
    <h1>Pay order <span class="mono">{{ $order->shortId() }}</span></h1>
    <p>Amount due: <strong>{{ money($order->total_minor, $order->currency) }}</strong>. Unpaid orders expire at {{ $order->expires_at?->format('Y-m-d H:i') }} UTC.</p>

    @if ($payment?->wallet_address)
        <div class="two-col">
            <section class="card" aria-labelledby="send-heading">
                <h2 id="send-heading">Send {{ $payment->crypto }}</h2>
                <p>Send exactly this amount:</p>
                <p class="price mono">{{ $payment->crypto_amount }} {{ $payment->crypto }}</p>
                <p>To this address:</p>
                <p class="mono">{{ $payment->wallet_address }}</p>
                @if ($qr)
                    <img class="qr" src="{{ $qr }}" alt="QR code containing the wallet address {{ $payment->wallet_address }}">
                @endif
                <p class="hint">Payments are confirmed by our payment server, not by this page. After the network confirms your transaction, your order will be marked paid and you will receive an email.</p>
                <p><a class="btn" href="{{ route('orders.result', $order) }}">I have sent the payment - check status</a></p>
            </section>

            <section aria-labelledby="switch-heading">
                <h2 id="switch-heading">Use another cryptocurrency</h2>
                <form method="post" action="{{ route('orders.pay.start', $order) }}" class="stack">
                    @csrf
                    <x-select name="crypto" label="Cryptocurrency" :options="collect($cryptos)->pluck('display_name', 'name')->all()" :value="$payment->crypto" />
                    <button type="submit" class="btn-secondary">Create new invoice</button>
                </form>
            </section>
        </div>
    @else
        <section class="card" aria-labelledby="choose-heading">
            <h2 id="choose-heading">Choose how to pay</h2>
            <form method="post" action="{{ route('orders.pay.start', $order) }}" class="stack" data-once>
                @csrf
                <x-select name="crypto" label="Cryptocurrency" :options="collect($cryptos)->pluck('display_name', 'name')->all()" />
                <button type="submit">Create crypto invoice</button>
            </form>
        </section>
    @endif

    @if ($user->currency === $order->currency && $user->balance_minor >= $order->total_minor)
        <h2>Pay from your balance</h2>
        <form method="post" action="{{ route('orders.pay.balance', $order) }}" data-once>
            @csrf
            <button type="submit" class="btn-secondary">Pay {{ money($order->total_minor, $order->currency) }} from balance ({{ money($user->balance_minor, $user->currency) }} available)</button>
        </form>
    @endif
@endsection
