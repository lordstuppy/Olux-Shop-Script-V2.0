{{ __('A refund of :amount was issued for order :order.', ['amount' => money($refund->amount_minor, $refund->currency), 'order' => $order->shortId()]) }}

@if ($refund->provider === \App\Enums\PaymentProvider::Balance)
{{ __('The amount was added to your shop balance.') }}
@elseif ($refund->provider === \App\Enums\PaymentProvider::Shkeeper)
{{ $refund->provider_reference ? __('It was sent in :crypto to :address (transaction :reference).', ['crypto' => $refund->crypto, 'address' => $refund->wallet_address, 'reference' => $refund->provider_reference]) : __('It was sent in :crypto to :address.', ['crypto' => $refund->crypto, 'address' => $refund->wallet_address]) }}
@else
{{ __('It was paid back outside the shop (reference :reference).', ['reference' => $refund->provider_reference]) }}
@endif

{{ __('Order details:') }} {{ route('orders.show', $order) }}
