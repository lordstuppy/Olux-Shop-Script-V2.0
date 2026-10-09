<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1b1f24; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border-bottom: 1px solid #c9ced6; padding: 6px; text-align: left; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <h1>Invoice {{ $invoice->number }}</h1>
    <p>{{ config('app.name') }}<br>Issued {{ $invoice->issued_at->format('Y-m-d') }}<br>Order {{ $order->public_id }}</p>
    <p>Billed to: {{ $order->buyer->name }} &lt;{{ $order->buyer->email }}&gt;</p>
    <table>
        <thead><tr><th>Item</th><th class="num">Unit</th><th class="num">Qty</th><th class="num">Discount</th><th class="num">Total</th></tr></thead>
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
            <tr><th colspan="4">Subtotal</th><td class="num">{{ money($order->subtotal_minor, $order->currency) }}</td></tr>
            <tr><th colspan="4">Discount</th><td class="num">{{ money($order->discount_minor, $order->currency) }}</td></tr>
            <tr><th colspan="4">Total paid</th><td class="num">{{ money($order->total_minor, $order->currency) }}</td></tr>
            @foreach ($order->refunds as $refund)
                <tr><th colspan="4">Refund {{ $refund->confirmed_at?->format('Y-m-d') }}</th><td class="num">-{{ money($refund->amount_minor, $refund->currency) }}</td></tr>
            @endforeach
        </tfoot>
    </table>
</body>
</html>
