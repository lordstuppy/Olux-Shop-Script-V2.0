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
        {{ $product->delivery_type === \App\Enums\DeliveryType::Instant ? 'Instant delivery' : 'Delivered by the seller' }}
        @if ($product->access_days)
            &middot; {{ $product->access_days }}-day access
        @endif
        @if ($product->stock !== null)
            &middot; {{ $product->stock > 0 ? $product->stock.' in stock' : 'Out of stock' }}
        @endif
    </p>
</article>
