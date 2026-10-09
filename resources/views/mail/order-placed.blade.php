Order {{ $order->shortId() }} was placed and is waiting for payment.

Total: {{ money($order->total_minor, $order->currency) }}
Pay with: {{ $payment->crypto }}

Payment instructions (sign in required): {{ route('orders.pay', $order) }}
Unpaid orders expire at {{ $order->expires_at?->format('Y-m-d H:i') }} UTC.

Only send funds to the address shown on the payment page. We never send payment addresses in emails.
