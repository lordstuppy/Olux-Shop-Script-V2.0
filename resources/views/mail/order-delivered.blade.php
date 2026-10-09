{{ __('An item in order :order was delivered.', ['order' => $order->shortId()]) }}

{{ __('View the delivery details:') }} {{ route('orders.show', $order) }}
