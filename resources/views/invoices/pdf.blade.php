<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice :number', ['number' => $invoice->number]) }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1b1f24; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border-bottom: 1px solid #c9ced6; padding: 6px; text-align: left; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ __('Invoice :number', ['number' => $invoice->number]) }}</h1>
    <p>{{ config('app.name') }}<br>{{ __('Issued :date', ['date' => $invoice->issued_at->format('Y-m-d')]) }}<br>{{ __('Order :order', ['order' => $order->public_id]) }}</p>
    <p>{{ __('Billed to:') }} {{ $order->buyer->name }} &lt;{{ $order->buyer->email }}&gt;</p>
    <table>
        <thead><tr><th>{{ __('Item') }}</th><th class="num">{{ __('Unit') }}</th><th class="num">{{ __('Qty') }}</th><th class="num">{{ __('Discount') }}</th><th class="num">{{ __('Total') }}</th></tr></thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $item->title }}</td>
                    <td class="num">{{ money($item->unit_price_minor, $order->currency) }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ money($item->discount_minor, $order->currency) }}</td>
                    <td class="num">{{ money($item->netMinor(), $order->currency) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><th colspan="4">{{ __('Subtotal') }}</th><td class="num">{{ money($order->subtotal_minor, $order->currency) }}</td></tr>
            <tr><th colspan="4">{{ __('Discount') }}</th><td class="num">{{ money($order->discount_minor, $order->currency) }}</td></tr>
            <tr><th colspan="4">{{ __('Total paid') }}</th><td class="num">{{ money($order->total_minor, $order->currency) }}</td></tr>
            @foreach ($order->refunds as $refund)
                <tr><th colspan="4">{{ __('Refund :date', ['date' => $refund->confirmed_at?->format('Y-m-d')]) }}</th><td class="num">-{{ money($refund->amount_minor, $refund->currency) }}</td></tr>
            @endforeach
        </tfoot>
    </table>
</body>
</html>
