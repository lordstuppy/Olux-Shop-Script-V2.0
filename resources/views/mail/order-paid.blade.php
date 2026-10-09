{{ __('Order :order paid.', ['order' => $order->shortId()]) }}

{{ __('Total:') }} {{ money($order->total_minor, $order->currency) }}

@foreach ($order->items as $item)
- {{ $item->title }} x {{ $item->quantity }}: {{ $item->isDelivered() ? __('delivered') : __('waiting for delivery') }}
@endforeach

{{ __('Download links and licence keys:') }} {{ route('orders.show', $order) }}
{{ __('(Sign in to open the link. Download links on that page expire after a limited time; reload the page for fresh links.)') }}
