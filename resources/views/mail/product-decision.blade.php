@if ($approved)
{{ __('Your product ":title" is approved and for sale.', ['title' => $product->title]) }}
@else
{{ __('Your product ":title" is not for sale.', ['title' => $product->title]) }}

{{ __('Reason:') }} {{ $note }}

{{ __('Fix what the reason mentions, then submit the product for review again.') }}
@endif

{{ route('seller.products.edit', $product->id) }}
