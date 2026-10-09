@extends('layouts.app')

@section('title', $product->title)
@section('description', \Illuminate\Support\Str::limit(strip_tags($product->description), 155))
@section('canonical', route('products.show', $product->slug))

@push('head')
    {{-- Structured data (a data block, not executable script; allowed by the CSP). --}}
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->title,
        'description' => \Illuminate\Support\Str::limit(strip_tags($product->description), 500),
        'sku' => (string) $product->id,
        'category' => $product->category?->name,
        'url' => route('products.show', $product->slug),
        'brand' => ['@type' => 'Brand', 'name' => $product->seller->sellerProfile?->display_name ?? config('app.name')],
        'offers' => [
            '@type' => 'Offer',
            'price' => \App\Support\Money::toDecimal($product->price_minor, $product->currency),
            'priceCurrency' => $product->currency,
            'availability' => $product->isPurchasable() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url' => route('products.show', $product->slug),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endpush

@section('content')
    <nav aria-label="Breadcrumb">
        <a href="{{ route('products.index') }}">Products</a>
        @if ($product->category)
            / <a href="{{ route('products.index', ['category' => $product->category->slug]) }}">{{ $product->category->name }}</a>
        @endif
    </nav>

    <div class="two-col">
        <div>
            <h1>{{ $product->title }}</h1>
            <p class="muted">Sold by {{ $product->seller->sellerProfile?->display_name ?? $product->seller->name }}</p>
            <div class="description">{{ $product->description }}</div>
        </div>

        <aside class="card" aria-labelledby="buy-heading">
            <h2 id="buy-heading">Buy</h2>
            <p class="price">{{ money($product->price_minor, $product->currency) }}</p>
            @if ($convertedMinor !== null)
                <p class="muted">About {{ money($convertedMinor, $cartCurrency) }} at the current shop rate.</p>
            @endif
            <p>{{ $product->delivery_type === \App\Enums\DeliveryType::Instant ? 'Instant delivery after payment confirmation.' : 'Delivered by the seller after payment, usually within 24 hours.' }}</p>
            @if ($product->stock !== null)
                <p>{{ $product->stock > 0 ? $product->stock.' in stock.' : 'Out of stock.' }}</p>
            @endif

            @if ($product->isPurchasable())
                <form method="post" action="{{ route('cart.add') }}" class="stack">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <x-field name="quantity" label="Quantity" type="number" value="1" min="1" :max="min(config('shop.max_quantity_per_line'), $product->stock ?? config('shop.max_quantity_per_line'))" required />
                    <button type="submit">Add to cart</button>
                </form>
            @endif
        </aside>
    </div>
@endsection
