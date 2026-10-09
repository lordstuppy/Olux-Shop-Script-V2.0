@extends('layouts.app')

@section('title', __('Order :order', ['order' => $order->shortId()]))
@section('noindex', true)

@section('content')
    <h1>{{ __('Order') }} <span class="mono">{{ $order->shortId() }}</span></h1>
    <p>{{ __('Status:') }} <x-status :value="$order->status" />. {{ __('Placed :time UTC.', ['time' => $order->created_at->format('Y-m-d H:i')]) }} {{ __('Full reference:') }} <span class="mono">{{ $order->public_id }}</span></p>

    @if ($order->status === \App\Enums\OrderStatus::Pending && auth()->id() === $order->buyer_id)
        <div class="actions">
            <a class="btn" href="{{ route('orders.pay', $order) }}">{{ __('Pay now') }}</a>
            <form method="post" action="{{ route('orders.cancel', $order) }}">
                @csrf
                <button type="submit" class="btn-secondary">{{ __('Cancel order') }}</button>
            </form>
        </div>
        @if ($order->expires_at)
            <p class="hint">{{ __('Unpaid orders expire at :time UTC.', ['time' => $order->expires_at->format('Y-m-d H:i')]) }}</p>
        @endif
    @endif

    <h2>{{ __('Items') }}</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">{{ __('Product') }}</th>
                    <th scope="col" class="num">{{ __('Qty') }}</th>
                    <th scope="col" class="num">{{ __('Total') }}</th>
                    <th scope="col">{{ __('Delivery') }}</th>
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
                                    {{ __('Waiting for delivery.') }}
                                @else
                                    {{ __('Delivered after payment.') }}
                                @endif
                            @elseif (auth()->id() !== $order->buyer_id)
                                {{ __('Delivered :time UTC.', ['time' => $item->delivered_at->format('Y-m-d H:i')]) }}
                            @else
                                @php $payload = $item->delivered_payload ?? []; @endphp
                                @if ($item->access_expires_at)
                                    <span class="{{ $item->accessExpired() ? 'error-text' : 'muted' }}">{{ $item->accessExpired() ? __('Access ended :date.', ['date' => $item->access_expires_at->format('Y-m-d')]) : __('Access until :date.', ['date' => $item->access_expires_at->format('Y-m-d')]) }}</span><br>
                                @endif
                                @if (! empty($payload['files']))
                                    <span class="muted">{{ __('Downloads used: :used of :limit.', ['used' => $item->download_count, 'limit' => $item->product->downloadLimit()]) }}</span><br>
                                @endif
                                @foreach ($downloads[$item->id] ?? [] as $download)
                                    <a href="{{ $download['url'] }}">{{ __('Download :name', ['name' => $download['name']]) }}</a> ({{ __(':size bytes', ['size' => number_format($download['size'])]) }})<br>
                                @endforeach
                                @foreach ($payload['license_keys'] ?? [] as $key)
                                    {{ __('Licence key:') }} <span class="mono">{{ $key }}</span><br>
                                @endforeach
                                @if (($payload['type'] ?? '') === 'manual')
                                    <div class="description">{{ $payload['text'] ?? '' }}</div>
                                @endif
                                @if (! empty($payload['note']))
                                    <div class="description">{{ $payload['note'] }}</div>
                                @endif
                                @if ($order->status === \App\Enums\OrderStatus::Refunded)
                                    <span class="muted">{{ __('This order was refunded; downloads are no longer available.') }}</span>
                                @endif
                            @endif
                            @if ($dispute = $disputes->get($item->id))
                                <br><a href="{{ route('disputes.show', $dispute) }}">{{ __('Dispute #:id', ['id' => $dispute->id]) }}</a>: <x-status :value="$dispute->status" />
                            @elseif (in_array($item->id, $disputable, true))
                                <br><a href="{{ route('disputes.create', [$order, $item]) }}">{{ __('Report a problem with this item') }}</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><th scope="row" colspan="2">{{ __('Subtotal') }}</th><td class="num">{{ money($order->subtotal_minor, $order->currency) }}</td><td></td></tr>
                @if ($order->discount_minor > 0)
                    <tr><th scope="row" colspan="2">{{ __('Discount') }}</th><td class="num">-{{ money($order->discount_minor, $order->currency) }}</td><td></td></tr>
                @endif
                <tr><th scope="row" colspan="2">{{ __('Total') }}</th><td class="num"><strong>{{ money($order->total_minor, $order->currency) }}</strong></td><td></td></tr>
                @if ($order->refunded_minor > 0)
                    <tr><th scope="row" colspan="2">{{ __('Refunded') }}</th><td class="num">{{ money($order->refunded_minor, $order->currency) }}</td><td></td></tr>
                @endif
            </tfoot>
        </table>
    </div>

    @if ($order->status->isPaidState())
        <p class="mt"><a href="{{ route('orders.invoice', $order) }}">{{ __('Download invoice (PDF)') }}</a></p>
    @endif

    <h2>{{ __('Payments') }}</h2>
    @if ($order->payments->isEmpty())
        <p>{{ __('No payment has been started.') }}</p>
    @else
        <ul>
            @foreach ($order->payments as $payment)
                <li>
                    {{ __(':kind via :provider', ['kind' => $payment->kind->label(), 'provider' => $payment->provider->label()]) }}{{ $payment->crypto ? ' ('.$payment->crypto.')' : '' }}:
                    {{ money($payment->amount_minor, $payment->currency) }}, <x-status :value="$payment->status" />
                    @if ($payment->status === \App\Enums\PaymentStatus::Partial)
                        {{ __('(received :received)', ['received' => money($payment->received_minor, $payment->currency)]) }}
                    @endif
                    @if ($payment->failure_reason)
                        <br><span class="error-text">{{ $payment->failure_reason }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if (auth()->id() === $order->buyer_id)
        <p class="mt"><a href="{{ route('tickets.create', ['order' => $order->public_id]) }}">{{ __('Report a problem with this order') }}</a></p>
    @endif
@endsection
