@extends('layouts.app')

@section('title', __('Products'))
@section('canonical', route('products.index', array_filter(['category' => $filters['category'] ?? null, 'page' => request('page') > 1 ? (int) request('page') : null])))

@section('content')
    <h1>{{ $sellerName ? __('Products by :seller', ['seller' => $sellerName]) : __('Products') }}</h1>

    <form class="filters" method="get" action="{{ route('products.index') }}" aria-label="{{ __('Filter products') }}">
        <x-field name="q" :label="__('Search')" type="search" :value="$filters['q'] ?? ''" maxlength="100" />
        <x-select name="category" :label="__('Category')" :options="$categories->pluck('name', 'slug')->all()" :value="$filters['category'] ?? ''" :placeholder="__('All categories')" />
        <x-select name="sort" :label="__('Sort by')" :options="['newest' => __('Newest'), 'best' => __('Best selling'), 'price_asc' => __('Price: low to high'), 'price_desc' => __('Price: high to low'), 'title' => __('Title')]" :value="$filters['sort'] ?? 'newest'" />
        <x-select name="seller" :label="__('Seller')" :options="$sellers->all()" :value="$filters['seller'] ?? ''" :placeholder="__('All sellers')" />
        <x-field name="price_min" :label="__('Min price (:currency)', ['currency' => $filters['price_currency']])" :value="$filters['price_min'] ?? ''" inputmode="decimal" maxlength="12" />
        <x-field name="price_max" :label="__('Max price (:currency)', ['currency' => $filters['price_currency']])" :value="$filters['price_max'] ?? ''" inputmode="decimal" maxlength="12" />
        <x-select name="currency" :label="__('Listed in')" :options="array_combine(\App\Support\Money::supported(), \App\Support\Money::supported())" :value="$filters['currency'] ?? ''" :placeholder="__('Any currency')" />
        <button type="submit">{{ __('Apply filters') }}</button>
    </form>

    <p class="muted" role="status">{{ trans_choice('{1} :count product found.|[0,*] :count products found.', $products->total()) }}</p>

    @if ($products->isEmpty())
        <p>{{ __('No products match these filters.') }} <a href="{{ route('products.index') }}">{{ __('Clear filters') }}</a>.</p>
    @else
        <div class="grid">
            @foreach ($products as $product)
                @include('partials.product-card', ['product' => $product, 'headingLevel' => 2])
            @endforeach
        </div>
        {{ $products->links() }}
    @endif
@endsection
