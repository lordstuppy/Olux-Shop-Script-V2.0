{{ __('Order :order was placed and is waiting for payment.', ['order' => $order->shortId()]) }}

{{ __('Total:') }} {{ money($order->total_minor, $order->currency) }}
{{ __('Pay with:') }} {{ $payment->crypto }}

{{ __('Payment instructions (sign in required):') }} {{ route('orders.pay', $order) }}
{{ __('Unpaid orders expire at :time UTC.', ['time' => $order->expires_at?->format('Y-m-d H:i')]) }}

{{ __('Only send funds to the address shown on the payment page. We never send payment addresses in emails.') }}
