Your access to "{{ $item->title }}" (order {{ $item->order->shortId() }}) ends on {{ $item->access_expires_at->format('Y-m-d H:i') }} UTC.

To keep access, renew it here: {{ $item->product ? route('products.show', $item->product->slug) : route('products.index') }}
