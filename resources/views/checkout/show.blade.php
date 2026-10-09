@extends('layouts.app')

@section('title', __('Checkout'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Checkout') }}</h1>

    @foreach ($totals['problems'] as $problem)
        <div class="flash flash-info" role="status">{{ $problem }}</div>
    @endforeach
    @if ($couponError)
        <div class="flash flash-error" role="alert">{{ $couponError }}</div>
    @endif

    <div class="two-col">
        <section aria-labelledby="summary-heading">
            <h2 id="summary-heading">{{ __('Order summary') }}</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th scope="col">{{ __('Product') }}</th><th scope="col" class="num">{{ __('Qty') }}</th><th scope="col" class="num">{{ __('Total') }}</th></tr>
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
                        <tr><th scope="row" colspan="2">{{ __('Subtotal') }}</th><td class="num">{{ money($totals['subtotal_minor'], $totals['currency']) }}</td></tr>
                        @if ($coupon)
                            <tr><th scope="row" colspan="2">{{ __('Coupon :code', ['code' => $coupon->code]) }}</th><td class="num">-{{ money($discountMinor, $totals['currency']) }}</td></tr>
                        @endif
                        <tr><th scope="row" colspan="2">{{ __('Total') }}</th><td class="num"><strong>{{ money($totalMinor, $totals['currency']) }}</strong></td></tr>
                    </tfoot>
                </table>
            </div>

            <h3>{{ __('Coupon') }}</h3>
            @if ($coupon)
                <form method="post" action="{{ route('checkout.coupon.remove') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-secondary">{{ __('Remove coupon :code', ['code' => $coupon->code]) }}</button>
                </form>
            @else
                <form method="post" action="{{ route('checkout.coupon') }}" class="actions">
                    @csrf
                    <x-field name="code" :label="__('Coupon code')" maxlength="40" autocomplete="off" />
                    <button type="submit" class="btn-secondary">{{ __('Apply coupon') }}</button>
                </form>
            @endif
        </section>

        <section class="card" aria-labelledby="pay-heading">
            <h2 id="pay-heading">{{ __('Payment') }}</h2>
            <form method="post" action="{{ route('checkout.store') }}" class="stack" data-once>
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                <fieldset>
                    <legend>{{ __('Payment method') }}</legend>
                    <label class="check">
                        <input type="radio" name="payment_method" value="crypto" @checked(old('payment_method', 'crypto') === 'crypto')>
                        {{ __('Cryptocurrency (self-hosted Shkeeper)') }}
                    </label>
                    <x-select name="crypto" :label="__('Cryptocurrency')" :options="collect($cryptos)->pluck('display_name', 'name')->all()" :value="old('crypto')" />
                    <label class="check">
                        <input type="radio" name="payment_method" value="balance" @checked(old('payment_method') === 'balance') @disabled(! $balanceUsable)>
                        {{ __('Shop balance (:balance)', ['balance' => money(auth()->user()->balance_minor, auth()->user()->currency)]) }}
                    </label>
                    @unless ($balanceUsable)
                        <p class="hint">{{ __('Your balance cannot cover :amount in :currency.', ['amount' => money($totalMinor, $totals['currency']), 'currency' => $totals['currency']]) }}</p>
                    @endunless
                </fieldset>

                <label class="check">
                    <input type="checkbox" name="accept_terms" value="1" required>
                    <span>{{ __('I accept the') }} <a href="{{ route('pages.terms') }}">{{ __('terms of service') }}</a>, {{ __('including that digital goods are delivered immediately after payment.') }}</span>
                </label>

                <button type="submit">{{ __('Place order and pay :amount', ['amount' => money($totalMinor, $totals['currency'])]) }}</button>
                <p class="hint">{{ __('The amount is calculated on our server. Your order is reserved for :minutes minutes while you pay.', ['minutes' => config('shop.order_ttl_minutes')]) }}</p>
            </form>
        </section>
    </div>
@endsection
