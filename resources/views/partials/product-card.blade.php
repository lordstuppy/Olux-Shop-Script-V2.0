<article class="card">
    <h3><a href="{{ route('products.show', $product->slug) }}">{{ $product->title }}</a></h3>
    @if ($product->category)
        <p class="muted">{{ $product->category->name }}</p>
    @endif
    <p class="price">{{ money($product->price_minor, $product->currency) }}</p>
    <p class="muted">
        {{ $product->delivery_type === \App\Enums\DeliveryType::Instant ? 'Instant delivery' : 'Delivered by the seller' }}
        @if ($product->stock !== null)
            &middot; {{ $product->stock > 0 ? $product->stock.' in stock' : 'Out of stock' }}
        @endif
    </p>
</article>
