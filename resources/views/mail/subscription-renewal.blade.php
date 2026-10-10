{!! __('Your access to ":title" (order :order) ends on :time UTC.', ['title' => e($item->title), 'order' => e($item->order->shortId()), 'time' => e($item->access_expires_at->format('Y-m-d H:i'))]) !!}

{!! __('To keep access, renew it here:') !!} {!! $item->product ? route('products.show', $item->product->slug) : route('products.index') !!}
