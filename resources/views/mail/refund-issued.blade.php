A refund of {{ money($refund->amount_minor, $refund->currency) }} was issued for order {{ $order->shortId() }}.

@if ($refund->provider === \App\Enums\PaymentProvider::Balance)
The amount was added to your shop balance.
@elseif ($refund->provider === \App\Enums\PaymentProvider::Shkeeper)
It was sent in {{ $refund->crypto }} to {{ $refund->wallet_address }}@if ($refund->provider_reference) (transaction {{ $refund->provider_reference }})@endif.
@else
It was paid back outside the shop (reference {{ $refund->provider_reference }}).
@endif

Order details: {{ route('orders.show', $order) }}
