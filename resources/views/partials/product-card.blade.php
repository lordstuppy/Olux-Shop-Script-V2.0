@php $level = $headingLevel ?? 3; @endphp
<article class="card">
    @php $cover = $product->images->first(); @endphp
    @if ($cover)
        <img class="card-image" src="{{ $cover->url('thumb') }}" alt="{{ $cover->alt_text ?? '' }}" width="480" height="{{ (int) round(480 * $cover->height / max(1, $cover->width)) }}" loading="lazy">
    @endif
    <h{{ $level }} class="card-title"><a href="{{ route('products.show', $product->slug) }}">{{ $product->title }}</a></h{{ $level }}>
    @if ($product->category)
        <p class="muted">{{ $product->category->name }}</p>
    @endif
    <p class="price">{{ money($product->price_minor, $product->currency) }}</p>
    <p class="muted">
        {{ $product->delivery_type === \App\Enums\DeliveryType::Instant ? __('Instant delivery') : __('Delivered by the seller') }}
        @if ($product->access_days)
            &middot; {{ __(':days-day access', ['days' => $product->access_days]) }}
        @endif
        @if ($product->stock !== null)
            &middot; {{ $product->stock > 0 ? __(':count in stock', ['count' => $product->stock]) : __('Out of stock') }}
        @endif
    </p>
</article>
