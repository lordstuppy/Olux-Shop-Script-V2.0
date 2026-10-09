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
        'image' => $product->images->map(fn ($i) => $i->url())->values()->all(),
        'aggregateRating' => $rating['count'] > 0 ? ['@type' => 'AggregateRating', 'ratingValue' => $rating['average'], 'reviewCount' => $rating['count']] : null,
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
            <p class="muted">Sold by {{ $product->seller->sellerProfile?->display_name ?? $product->seller->name }}
                @if ($rating['count'] > 0)
                    &middot; <a href="#reviews">Rated {{ number_format($rating['average'], 1) }} out of 5 ({{ $rating['count'] }} {{ $rating['count'] === 1 ? 'review' : 'reviews' }})</a>
                @endif
            </p>
            @if ($product->images->isNotEmpty())
                @php $main = $product->images->first(); @endphp
                <figure class="gallery">
                    <a href="{{ $main->url() }}"><img src="{{ $main->url() }}" alt="{{ $main->alt_text ?? $product->title }}" width="{{ $main->width }}" height="{{ $main->height }}"></a>
                    @if ($product->images->count() > 1)
                        <ul class="thumbs">
                            @foreach ($product->images->skip(1) as $image)
                                <li><a href="{{ $image->url() }}"><img src="{{ $image->url('thumb') }}" alt="{{ $image->alt_text ?? 'Additional image of '.$product->title }}" loading="lazy"></a></li>
                            @endforeach
                        </ul>
                    @endif
                </figure>
            @endif
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
            @if ($product->access_days)
                <p>Subscription: {{ $product->access_days }} days of access per unit, starting at delivery. To renew, buy it again; we email you a reminder before access ends.</p>
            @endif
            @if ($product->delivery_type === \App\Enums\DeliveryType::Instant && $product->activeFiles->isNotEmpty())
                <p class="hint">Includes {{ $product->activeFiles->count() }} {{ $product->activeFiles->count() === 1 ? 'file' : 'files' }}; up to {{ $product->downloadLimit() }} downloads per purchase.</p>
            @endif

            @if ($product->isPurchasable())
                <form method="post" action="{{ route('cart.add') }}" class="stack">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <x-field name="quantity" label="Quantity" type="number" value="1" min="1" :max="min(config('shop.max_quantity_per_line'), $product->stock ?? config('shop.max_quantity_per_line'))" required />
                    <button type="submit">Add to cart</button>
                </form>
            @endif
            @auth
                @if ($wishlisted)
                    <form method="post" action="{{ route('wishlist.destroy', $product->id) }}" class="mt">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-secondary">Remove from wishlist</button>
                    </form>
                @else
                    <form method="post" action="{{ route('wishlist.store') }}" class="mt">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                        <button type="submit" class="btn-secondary">Save to wishlist</button>
                    </form>
                @endif
            @endauth
        </aside>
    </div>

    <section id="reviews" aria-labelledby="reviews-heading">
        <h2 id="reviews-heading">Reviews</h2>
        @if ($rating['count'] > 0)
            <p>Average {{ number_format($rating['average'], 1) }} out of 5 from {{ $rating['count'] }} verified {{ $rating['count'] === 1 ? 'buyer' : 'buyers' }}.</p>
        @endif
        @forelse ($reviews as $review)
            <article class="message">
                <h3>{{ $review->title }}</h3>
                <p class="meta"><span aria-label="{{ $review->rating }} out of 5 stars">{{ str_repeat('*', $review->rating) }}{{ str_repeat('-', 5 - $review->rating) }}</span> &middot; {{ $review->user->name }} &middot; {{ $review->created_at->format('Y-m-d') }} &middot; Verified buyer</p>
                <div class="description">{{ $review->body }}</div>
            </article>
        @empty
            <p>No reviews yet.</p>
        @endforelse

        @if ($canReview)
            <h3>{{ $myReview ? 'Edit your review' : 'Write a review' }}</h3>
            <form method="post" action="{{ route('products.reviews.store', $product->slug) }}" class="stack">
                @csrf
                <x-select name="rating" label="Rating" :options="[5 => '5 - excellent', 4 => '4 - good', 3 => '3 - okay', 2 => '2 - poor', 1 => '1 - bad']" :value="$myReview?->rating ?? 5" />
                <x-field name="title" label="Title" :value="$myReview?->title" maxlength="120" required />
                <x-textarea name="body" label="Your review" :value="$myReview?->body" maxlength="3000" required />
                <button type="submit">{{ $myReview ? 'Update review' : 'Publish review' }}</button>
            </form>
        @endif
    </section>

    @if ($related->isNotEmpty())
        <section aria-labelledby="related-heading">
            <h2 id="related-heading">Related products</h2>
            <div class="grid">
                @foreach ($related as $item)
                    @include('partials.product-card', ['product' => $item, 'headingLevel' => 3])
                @endforeach
            </div>
        </section>
    @endif
@endsection
