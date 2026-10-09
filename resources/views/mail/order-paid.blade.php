Order {{ $order->shortId() }} paid.

Total: {{ money($order->total_minor, $order->currency) }}

@foreach ($order->items as $item)
- {{ $item->title }} x {{ $item->quantity }}: {{ $item->isDelivered() ? 'delivered' : 'waiting for delivery' }}
@endforeach

Download links and licence keys: {{ route('orders.show', $order) }}
(Sign in to open the link. Download links on that page expire after a limited time; reload the page for fresh links.)
