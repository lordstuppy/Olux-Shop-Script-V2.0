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
    <nav aria-label="{{ __('Breadcrumb') }}">
        <a href="{{ route('products.index') }}">{{ __('Products') }}</a>
        @if ($product->category)
            / <a href="{{ route('products.index', ['category' => $product->category->slug]) }}">{{ $product->category->name }}</a>
        @endif
    </nav>

    <div class="two-col">
        <div>
            <h1>{{ $product->title }}</h1>
            <p class="muted">{{ __('Sold by :seller', ['seller' => $product->seller->sellerProfile?->display_name ?? $product->seller->name]) }}
                @if ($rating['count'] > 0)
                    &middot; <a href="#reviews">{{ trans_choice('{1} Rated :rating out of 5 (:count review)|[2,*] Rated :rating out of 5 (:count reviews)', $rating['count'], ['rating' => number_format($rating['average'], 1)]) }}</a>
                @endif
            </p>
            @if ($product->images->isNotEmpty())
                @php $main = $product->images->first(); @endphp
                <figure class="gallery">
                    <a href="{{ $main->url() }}"><img src="{{ $main->url() }}" alt="{{ $main->alt_text ?? $product->title }}" width="{{ $main->width }}" height="{{ $main->height }}"></a>
                    @if ($product->images->count() > 1)
                        <ul class="thumbs">
                            @foreach ($product->images->skip(1) as $image)
                                <li><a href="{{ $image->url() }}"><img src="{{ $image->url('thumb') }}" alt="{{ $image->alt_text ?? __('Additional image of :title', ['title' => $product->title]) }}" loading="lazy"></a></li>
                            @endforeach
                        </ul>
                    @endif
                </figure>
            @endif
            <div class="description">{{ $product->description }}</div>
        </div>

        <aside class="card" aria-labelledby="buy-heading">
            <h2 id="buy-heading">{{ __('Buy') }}</h2>
            <p class="price">{{ money($product->price_minor, $product->currency) }}</p>
            @if ($convertedMinor !== null)
                <p class="muted">{{ __('About :amount at the current shop rate.', ['amount' => money($convertedMinor, $cartCurrency)]) }}</p>
            @endif
            <p>{{ $product->delivery_type === \App\Enums\DeliveryType::Instant ? __('Instant delivery after payment confirmation.') : __('Delivered by the seller after payment, usually within 24 hours.') }}</p>
            @if ($product->stock !== null)
                <p>{{ $product->stock > 0 ? __(':count in stock.', ['count' => $product->stock]) : __('Out of stock.') }}</p>
            @endif
            @if ($product->access_days)
                <p>{{ __('Subscription: :days days of access per unit, starting at delivery. To renew, buy it again; we email you a reminder before access ends.', ['days' => $product->access_days]) }}</p>
            @endif
            @if ($product->delivery_type === \App\Enums\DeliveryType::Instant && $product->activeFiles->isNotEmpty())
                <p class="hint">{{ trans_choice('{1} Includes :count file; up to :limit downloads per purchase.|[2,*] Includes :count files; up to :limit downloads per purchase.', $product->activeFiles->count(), ['limit' => $product->downloadLimit()]) }}</p>
            @endif

            @if ($product->isPurchasable())
                <form method="post" action="{{ route('cart.add') }}" class="stack">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <x-field name="quantity" :label="__('Quantity')" type="number" value="1" min="1" :max="min(config('shop.max_quantity_per_line'), $product->stock ?? config('shop.max_quantity_per_line'))" required />
                    <button type="submit">{{ __('Add to cart') }}</button>
                </form>
            @endif
            @auth
                @if ($wishlisted)
                    <form method="post" action="{{ route('wishlist.destroy', $product->id) }}" class="mt">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-secondary">{{ __('Remove from wishlist') }}</button>
                    </form>
                @else
                    <form method="post" action="{{ route('wishlist.store') }}" class="mt">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                        <button type="submit" class="btn-secondary">{{ __('Save to wishlist') }}</button>
                    </form>
                @endif
            @endauth
        </aside>
    </div>

    <section id="reviews" aria-labelledby="reviews-heading">
        <h2 id="reviews-heading">{{ __('Reviews') }}</h2>
        @if ($rating['count'] > 0)
            <p>{{ trans_choice('{1} Average :rating out of 5 from :count verified buyer.|[2,*] Average :rating out of 5 from :count verified buyers.', $rating['count'], ['rating' => number_format($rating['average'], 1)]) }}</p>
        @endif
        @forelse ($reviews as $review)
            <article class="message">
                <h3>{{ $review->title }}</h3>
                <p class="meta"><span aria-label="{{ __(':rating out of 5 stars', ['rating' => $review->rating]) }}">{{ str_repeat('*', $review->rating) }}{{ str_repeat('-', 5 - $review->rating) }}</span> &middot; {{ $review->user->name }} &middot; {{ $review->created_at->format('Y-m-d') }} &middot; {{ __('Verified buyer') }}</p>
                <div class="description">{{ $review->body }}</div>
            </article>
        @empty
            <p>{{ __('No reviews yet.') }}</p>
        @endforelse

        @if ($canReview)
            <h3>{{ $myReview ? __('Edit your review') : __('Write a review') }}</h3>
            <form method="post" action="{{ route('products.reviews.store', $product->slug) }}" class="stack">
                @csrf
                <x-select name="rating" :label="__('Rating')" :options="[5 => __('5 - excellent'), 4 => __('4 - good'), 3 => __('3 - okay'), 2 => __('2 - poor'), 1 => __('1 - bad')]" :value="$myReview?->rating ?? 5" />
                <x-field name="title" :label="__('Title')" :value="$myReview?->title" maxlength="120" required />
                <x-textarea name="body" :label="__('Your review')" :value="$myReview?->body" maxlength="3000" required />
                <button type="submit">{{ $myReview ? __('Update review') : __('Publish review') }}</button>
            </form>
        @endif
    </section>

    @if ($related->isNotEmpty())
        <section aria-labelledby="related-heading">
            <h2 id="related-heading">{{ __('Related products') }}</h2>
            <div class="grid">
                @foreach ($related as $item)
                    @include('partials.product-card', ['product' => $item, 'headingLevel' => 3])
                @endforeach
            </div>
        </section>
    @endif
@endsection
