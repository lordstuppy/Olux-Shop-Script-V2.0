{{ __('You sold items in order :order:', ['order' => $order->shortId()]) }}

@foreach ($order->items->where('seller_id', $seller->id) as $item)
- {{ $item->title }} x {{ $item->quantity }}: {{ money($item->netMinor(), $order->currency) }}{{ $item->product?->delivery_type === \App\Enums\DeliveryType::Manual ? ' '.__('(you must deliver this item)') : '' }}
@endforeach

@if ($order->items->where('seller_id', $seller->id)->contains(fn ($i) => $i->product?->delivery_type === \App\Enums\DeliveryType::Manual))
{{ __('Deliver manual items from your dashboard:') }} {{ route('seller.dashboard') }}
@endif
{{ __('Sales overview:') }} {{ route('seller.sales') }}
