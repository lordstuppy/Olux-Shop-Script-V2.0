@extends('layouts.app')

@section('title', 'Order '.$order->shortId())
@section('noindex', true)

@section('content')
    <h1>Order <span class="mono">{{ $order->shortId() }}</span></h1>
    <p>Status: <x-status :value="$order->status" />. Placed {{ $order->created_at->format('Y-m-d H:i') }} UTC. Full reference: <span class="mono">{{ $order->public_id }}</span></p>

    @if ($order->status === \App\Enums\OrderStatus::Pending && auth()->id() === $order->buyer_id)
        <div class="actions">
            <a class="btn" href="{{ route('orders.pay', $order) }}">Pay now</a>
            <form method="post" action="{{ route('orders.cancel', $order) }}">
                @csrf
                <button type="submit" class="btn-secondary">Cancel order</button>
            </form>
        </div>
        @if ($order->expires_at)
            <p class="hint">Unpaid orders expire at {{ $order->expires_at->format('Y-m-d H:i') }} UTC.</p>
        @endif
    @endif

    <h2>Items</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col" class="num">Qty</th>
                    <th scope="col" class="num">Total</th>
                    <th scope="col">Delivery</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->title }}</td>
                        <td class="num">{{ $item->quantity }}</td>
                        <td class="num">{{ money($item->netMinor(), $order->currency) }}</td>
                        <td>
                            @if (! $item->isDelivered())
                                @if ($order->status->isPaidState())
                                    Waiting for delivery.
                                @else
                                    Delivered after payment.
                                @endif
                            @elseif (auth()->id() !== $order->buyer_id)
                                Delivered {{ $item->delivered_at->format('Y-m-d H:i') }} UTC.
                            @else
                                @php $payload = $item->delivered_payload ?? []; @endphp
                                @if ($item->access_expires_at)
                                    <span class="{{ $item->accessExpired() ? 'error-text' : 'muted' }}">{{ $item->accessExpired() ? 'Access ended' : 'Access until' }} {{ $item->access_expires_at->format('Y-m-d') }}.</span><br>
                                @endif
                                @if (! empty($payload['files']))
                                    <span class="muted">Downloads used: {{ $item->download_count }} of {{ $item->product->downloadLimit() }}.</span><br>
                                @endif
                                @foreach ($downloads[$item->id] ?? [] as $download)
                                    <a href="{{ $download['url'] }}">Download {{ $download['name'] }}</a> ({{ number_format($download['size']) }} bytes)<br>
                                @endforeach
                                @foreach ($payload['license_keys'] ?? [] as $key)
                                    Licence key: <span class="mono">{{ $key }}</span><br>
                                @endforeach
                                @if (($payload['type'] ?? '') === 'manual')
                                    <div class="description">{{ $payload['text'] ?? '' }}</div>
                                @endif
                                @if ($order->status === \App\Enums\OrderStatus::Refunded)
                                    <span class="muted">This order was refunded; downloads are no longer available.</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><th scope="row" colspan="2">Subtotal</th><td class="num">{{ money($order->subtotal_minor, $order->currency) }}</td><td></td></tr>
                @if ($order->discount_minor > 0)
                    <tr><th scope="row" colspan="2">Discount</th><td class="num">-{{ money($order->discount_minor, $order->currency) }}</td><td></td></tr>
                @endif
                <tr><th scope="row" colspan="2">Total</th><td class="num"><strong>{{ money($order->total_minor, $order->currency) }}</strong></td><td></td></tr>
                @if ($order->refunded_minor > 0)
                    <tr><th scope="row" colspan="2">Refunded</th><td class="num">{{ money($order->refunded_minor, $order->currency) }}</td><td></td></tr>
                @endif
            </tfoot>
        </table>
    </div>

    @if ($order->status->isPaidState())
        <p class="mt"><a href="{{ route('orders.invoice', $order) }}">Download invoice (PDF)</a></p>
    @endif

    <h2>Payments</h2>
    @if ($order->payments->isEmpty())
        <p>No payment has been started.</p>
    @else
        <ul>
            @foreach ($order->payments as $payment)
                <li>
                    {{ ucfirst($payment->kind->value) }} via {{ $payment->provider->value }}{{ $payment->crypto ? ' ('.$payment->crypto.')' : '' }}:
                    {{ money($payment->amount_minor, $payment->currency) }}, <x-status :value="$payment->status" />
                    @if ($payment->failure_reason)
                        <br><span class="error-text">{{ $payment->failure_reason }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if (auth()->id() === $order->buyer_id)
        <p class="mt"><a href="{{ route('tickets.create', ['order' => $order->public_id]) }}">Report a problem with this order</a></p>
    @endif
@endsection
