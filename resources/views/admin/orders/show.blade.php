@extends('layouts.app')

@section('title', __('Order :order - Admin', ['order' => $order->shortId()]))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Order') }} <span class="mono">{{ $order->public_id }}</span></h1>
    <p>{{ __('Status') }} <x-status :value="$order->status" /> &middot; {{ __('Buyer') }} <a href="{{ route('admin.users.show', $order->buyer) }}">{{ $order->buyer->email }}</a>
        &middot; {{ __('Created :time', ['time' => $order->created_at->format('Y-m-d H:i')]) }} &middot; {{ __('Paid :time', ['time' => $order->paid_at?->format('Y-m-d H:i') ?? '-']) }}
        @if ($order->coupon) &middot; {{ __('Coupon :code', ['code' => $order->coupon->code]) }} @endif
        @if ($order->invoice) &middot; {{ __('Invoice :number', ['number' => $order->invoice->number]) }} @endif
    </p>

    <h2>{{ __('Actions') }}</h2>
    <div class="actions">
        @can('orders.manage')
            @if ($order->status === \App\Enums\OrderStatus::Pending)
                <form method="post" action="{{ route('admin.orders.cancel', $order) }}">
                    @csrf
                    <button type="submit" class="btn-danger">{{ __('Cancel order') }}</button>
                </form>
            @endif
            @if ($order->status->isPaidState())
                <form method="post" action="{{ route('admin.orders.invoice', $order) }}">
                    @csrf
                    <button type="submit" class="btn-secondary">{{ __('Regenerate invoice') }}</button>
                </form>
            @endif
        @endcan
        @can('orders.resend')
            @if ($order->status->isPaidState())
                <form method="post" action="{{ route('admin.orders.redeliver', $order) }}">
                    @csrf
                    <button type="submit" class="btn-secondary">{{ __('Retry delivery and resend email') }}</button>
                </form>
            @endif
        @endcan
    </div>

    <h2>{{ __('Items') }}</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Product') }}</th><th scope="col">{{ __('Seller') }}</th><th scope="col" class="num">{{ __('Unit') }}</th><th scope="col" class="num">{{ __('Qty') }}</th><th scope="col" class="num">{{ __('Discount') }}</th><th scope="col" class="num">{{ __('Refunded') }}</th><th scope="col" class="num">{{ __('Seller earning') }}</th><th scope="col">{{ __('FX') }}</th><th scope="col">{{ __('Delivered') }}</th></tr></thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->title }}</td>
                        <td>{{ $item->seller->email }}</td>
                        <td class="num">{{ money($item->unit_price_minor, $order->currency) }}</td>
                        <td class="num">{{ $item->quantity }}</td>
                        <td class="num">{{ money($item->discount_minor, $order->currency) }}</td>
                        <td class="num">{{ money($item->refunded_minor, $order->currency) }}</td>
                        <td class="num">{{ money($item->seller_earning_minor, $order->currency) }} {{ __('(:percent% fee)', ['percent' => $item->commission_bps / 100]) }}</td>
                        <td>{{ money($item->list_price_minor, $item->list_currency) }} x {{ rtrim(rtrim((string) $item->fx_rate, '0'), '.') }}</td>
                        <td>
                            {{ $item->delivered_at?->format('Y-m-d H:i') ?? __('No') }}
                            @if ($item->delivered_at) <br><span class="muted">{{ __(':count downloads', ['count' => $item->download_count]) }}</span>@endif
                            @can('orders.manage')
                                @if ($item->download_count > 0)
                                    <form method="post" action="{{ route('admin.orders.items.reset-downloads', [$order, $item]) }}">
                                        @csrf
                                        <button type="submit" class="btn-link">{{ __('Reset downloads') }}<span class="visually-hidden"> {{ __('for :title', ['title' => $item->title]) }}</span></button>
                                    </form>
                                @endif
                                @if (! $item->delivered_at && in_array($order->status, [\App\Enums\OrderStatus::Paid, \App\Enums\OrderStatus::Delivered], true))
                                    <form method="post" action="{{ route('admin.orders.items.deliver', [$order, $item]) }}" class="stack">
                                        @csrf
                                        <x-textarea name="payload" :id="'payload-'.$item->id" :label="__('Deliver on the seller\'s behalf')" maxlength="10000" required />
                                        <button type="submit" class="btn-secondary">{{ __('Deliver') }}<span class="visually-hidden"> {{ $item->title }}</span></button>
                                    </form>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><th scope="row" colspan="6">{{ __('Total') }}</th><td class="num" colspan="3">{{ money($order->total_minor, $order->currency) }} {{ __('(refunded :amount)', ['amount' => money($order->refunded_minor, $order->currency)]) }}</td></tr>
            </tfoot>
        </table>
    </div>

    <h2>{{ __('Payments') }}</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">#</th><th scope="col">{{ __('Kind') }}</th><th scope="col">{{ __('Provider') }}</th><th scope="col">{{ __('Reference') }}</th><th scope="col" class="num">{{ __('Amount') }}</th><th scope="col" class="num">{{ __('Received') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Confirmed') }}</th></tr></thead>
            <tbody>
                @forelse ($order->payments as $payment)
                    <tr>
                        <td>{{ $payment->id }}</td>
                        <td>{{ $payment->kind->label() }}{{ $payment->parent ? ' '.__('of #:id', ['id' => $payment->parent->id]) : '' }}</td>
                        <td>{{ $payment->provider->label() }} {{ $payment->crypto }}</td>
                        <td class="mono">{{ $payment->provider_reference }}</td>
                        <td class="num">{{ money($payment->amount_minor, $payment->currency) }}</td>
                        <td class="num">{{ money($payment->received_minor, $payment->currency) }}</td>
                        <td><x-status :value="$payment->status" /> {{ $payment->failure_reason }}</td>
                        <td>{{ $payment->confirmed_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8">{{ __('No payments.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($order->status->isPaidState() && $order->refundableMinor() > 0 && auth()->user()->can('orders.manage'))
        <h2>{{ __('Refund') }}</h2>
        <p>{{ __('Refundable: :amount. Each refund is recorded as a separate payment and adjusts seller earnings.', ['amount' => money($order->refundableMinor(), $order->currency)]) }}</p>
        <form method="post" action="{{ route('admin.orders.refund', $order) }}" class="stack" data-once>
            @csrf
            <x-field name="amount" :label="__('Amount (:currency)', ['currency' => $order->currency])" :value="\App\Support\Money::toDecimal($order->refundableMinor(), $order->currency)" inputmode="decimal" required />
            <x-select name="method" :label="__('Method')" :options="array_merge(['balance' => __('Credit buyer balance'), 'manual' => __('Paid back outside the shop')], $shkeeperEnabled ? ['shkeeper' => __('Send crypto via Shkeeper')] : [])" />
            <x-field name="reference" :label="__('Outgoing transaction reference (manual only)')" maxlength="128" />
            @if ($shkeeperEnabled)
                <fieldset>
                    <legend>{{ __('Crypto refund via Shkeeper') }}</legend>
                    <x-select name="crypto" :label="__('Cryptocurrency')" :options="collect($cryptos)->pluck('display_name', 'name')->all()" :placeholder="__('Choose')" />
                    <x-field name="destination" :label="__('Buyer\'s destination address')" maxlength="255" autocomplete="off" />
                </fieldset>
            @endif
            <x-field name="reason" :label="__('Reason')" maxlength="500" />
            <button type="submit" class="btn-danger">{{ __('Record refund') }}</button>
        </form>
    @endif

    <p class="mt"><a href="{{ route('admin.audit.index', ['target_type' => 'Order', 'target_id' => $order->id]) }}">{{ __('Audit entries for this order') }}</a></p>
@endsection
