@extends('layouts.app')

@section('title', 'Order '.$order->shortId().' - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Order <span class="mono">{{ $order->public_id }}</span></h1>
    <p>Status <x-status :value="$order->status" /> &middot; Buyer <a href="{{ route('admin.users.show', $order->buyer) }}">{{ $order->buyer->email }}</a>
        &middot; Created {{ $order->created_at->format('Y-m-d H:i') }} &middot; Paid {{ $order->paid_at?->format('Y-m-d H:i') ?? '-' }}
        @if ($order->coupon) &middot; Coupon {{ $order->coupon->code }} @endif
        @if ($order->invoice) &middot; Invoice {{ $order->invoice->number }} @endif
    </p>

    <h2>Items</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Product</th><th scope="col">Seller</th><th scope="col" class="num">Unit</th><th scope="col" class="num">Qty</th><th scope="col" class="num">Discount</th><th scope="col" class="num">Refunded</th><th scope="col" class="num">Seller earning</th><th scope="col">FX</th><th scope="col">Delivered</th></tr></thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->title }}</td>
                        <td>{{ $item->seller->email }}</td>
                        <td class="num">{{ money($item->unit_price_minor, $order->currency) }}</td>
                        <td class="num">{{ $item->quantity }}</td>
                        <td class="num">{{ money($item->discount_minor, $order->currency) }}</td>
                        <td class="num">{{ money($item->refunded_minor, $order->currency) }}</td>
                        <td class="num">{{ money($item->seller_earning_minor, $order->currency) }} ({{ $item->commission_bps / 100 }}% fee)</td>
                        <td>{{ money($item->list_price_minor, $item->list_currency) }} x {{ rtrim(rtrim((string) $item->fx_rate, '0'), '.') }}</td>
                        <td>{{ $item->delivered_at?->format('Y-m-d H:i') ?? 'No' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><th scope="row" colspan="6">Total</th><td class="num" colspan="3">{{ money($order->total_minor, $order->currency) }} (refunded {{ money($order->refunded_minor, $order->currency) }})</td></tr>
            </tfoot>
        </table>
    </div>

    <h2>Payments</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">#</th><th scope="col">Kind</th><th scope="col">Provider</th><th scope="col">Reference</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Received</th><th scope="col">Status</th><th scope="col">Confirmed</th></tr></thead>
            <tbody>
                @forelse ($order->payments as $payment)
                    <tr>
                        <td>{{ $payment->id }}</td>
                        <td>{{ $payment->kind->value }}{{ $payment->parent ? ' of #'.$payment->parent->id : '' }}</td>
                        <td>{{ $payment->provider->value }} {{ $payment->crypto }}</td>
                        <td class="mono">{{ $payment->provider_reference }}</td>
                        <td class="num">{{ money($payment->amount_minor, $payment->currency) }}</td>
                        <td class="num">{{ money($payment->received_minor, $payment->currency) }}</td>
                        <td><x-status :value="$payment->status" /> {{ $payment->failure_reason }}</td>
                        <td>{{ $payment->confirmed_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8">No payments.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($order->status->isPaidState() && $order->refundableMinor() > 0)
        <h2>Refund</h2>
        <p>Refundable: {{ money($order->refundableMinor(), $order->currency) }}. Each refund is recorded as a separate payment and adjusts seller earnings.</p>
        <form method="post" action="{{ route('admin.orders.refund', $order) }}" class="stack" data-once>
            @csrf
            <x-field name="amount" label="Amount ({{ $order->currency }})" :value="\App\Support\Money::toDecimal($order->refundableMinor(), $order->currency)" inputmode="decimal" required />
            <x-select name="method" label="Method" :options="['balance' => 'Credit buyer balance', 'manual' => 'Paid back outside the shop']" />
            <x-field name="reference" label="Outgoing transaction reference (manual only)" maxlength="128" />
            <x-field name="reason" label="Reason" maxlength="500" />
            <button type="submit" class="btn-danger">Record refund</button>
        </form>
    @endif

    <p class="mt"><a href="{{ route('admin.audit.index', ['target_type' => 'Order', 'target_id' => $order->id]) }}">Audit entries for this order</a></p>
@endsection
