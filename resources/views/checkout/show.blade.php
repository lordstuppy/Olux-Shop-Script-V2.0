@extends('layouts.app')

@section('title', 'Checkout')
@section('noindex', true)

@section('content')
    <h1>Checkout</h1>

    @foreach ($totals['problems'] as $problem)
        <div class="flash flash-info" role="status">{{ $problem }}</div>
    @endforeach
    @if ($couponError)
        <div class="flash flash-error" role="alert">{{ $couponError }}</div>
    @endif

    <div class="two-col">
        <section aria-labelledby="summary-heading">
            <h2 id="summary-heading">Order summary</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th scope="col">Product</th><th scope="col" class="num">Qty</th><th scope="col" class="num">Total</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($totals['lines'] as $line)
                            <tr>
                                <td>{{ $line['product']->title }}</td>
                                <td class="num">{{ $line['quantity'] }}</td>
                                <td class="num">{{ money($line['line_minor'], $totals['currency']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr><th scope="row" colspan="2">Subtotal</th><td class="num">{{ money($totals['subtotal_minor'], $totals['currency']) }}</td></tr>
                        @if ($coupon)
                            <tr><th scope="row" colspan="2">Coupon {{ $coupon->code }}</th><td class="num">-{{ money($discountMinor, $totals['currency']) }}</td></tr>
                        @endif
                        <tr><th scope="row" colspan="2">Total</th><td class="num"><strong>{{ money($totalMinor, $totals['currency']) }}</strong></td></tr>
                    </tfoot>
                </table>
            </div>

            <h3>Coupon</h3>
            @if ($coupon)
                <form method="post" action="{{ route('checkout.coupon.remove') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-secondary">Remove coupon {{ $coupon->code }}</button>
                </form>
            @else
                <form method="post" action="{{ route('checkout.coupon') }}" class="actions">
                    @csrf
                    <x-field name="code" label="Coupon code" maxlength="40" autocomplete="off" />
                    <button type="submit" class="btn-secondary">Apply coupon</button>
                </form>
            @endif
        </section>

        <section class="card" aria-labelledby="pay-heading">
            <h2 id="pay-heading">Payment</h2>
            <form method="post" action="{{ route('checkout.store') }}" class="stack" data-once>
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                <fieldset>
                    <legend>Payment method</legend>
                    <label class="check">
                        <input type="radio" name="payment_method" value="crypto" @checked(old('payment_method', 'crypto') === 'crypto')>
                        Cryptocurrency (self-hosted Shkeeper)
                    </label>
                    <x-select name="crypto" label="Cryptocurrency" :options="collect($cryptos)->pluck('display_name', 'name')->all()" :value="old('crypto')" />
                    <label class="check">
                        <input type="radio" name="payment_method" value="balance" @checked(old('payment_method') === 'balance') @disabled(! $balanceUsable)>
                        Shop balance ({{ money(auth()->user()->balance_minor, auth()->user()->currency) }})
                    </label>
                    @unless ($balanceUsable)
                        <p class="hint">Your balance cannot cover {{ money($totalMinor, $totals['currency']) }} in {{ $totals['currency'] }}.</p>
                    @endunless
                </fieldset>

                <label class="check">
                    <input type="checkbox" name="accept_terms" value="1" required>
                    <span>I accept the <a href="{{ route('pages.terms') }}">terms of service</a>, including that digital goods are delivered immediately after payment.</span>
                </label>

                <button type="submit">Place order and pay {{ money($totalMinor, $totals['currency']) }}</button>
                <p class="hint">The amount is calculated on our server. Your order is reserved for {{ config('shop.order_ttl_minutes') }} minutes while you pay.</p>
            </form>
        </section>
    </div>
@endsection
